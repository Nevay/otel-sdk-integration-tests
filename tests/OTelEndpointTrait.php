<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\Socket\InternetAddress;
use Closure;
use Composer\InstalledVersions;
use Google\Protobuf\PrintOptions;
use JsonPath\JsonObject;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceResponse;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceResponse;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Path;
use function Amp\ByteStream\buffer;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\pipe;
use function Amp\File\deleteFile;
use function Amp\File\write;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use const PHP_BINARY;

trait OTelEndpointTrait {

    private HttpServer $server;
    public array $traces = [];
    public array $metrics = [];
    public array $logs = [];

    /**
     * Request headers of every captured OTLP export request, in order.
     *
     * @var list<array<string, string>>
     */
    public array $requestHeaders = [];

    public array $env = [];

    protected function setUp(): void {
        $this->requestHeaders = [];

        $server = SocketHttpServer::createForDirectAccess(new NullLogger());

        $router = new Router($server, new NullLogger(), new DefaultErrorHandler());
        $router->addRoute('POST', 'v1/traces', new ClosureRequestHandler(function (Request $request): Response {
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->traces[], ExportTraceServiceRequest::class, ExportTraceServiceResponse::class);
        }));
        $router->addRoute('POST', 'v1/metrics', new ClosureRequestHandler(function (Request $request): Response {
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->metrics[], ExportMetricsServiceRequest::class, ExportMetricsServiceResponse::class);
        }));
        $router->addRoute('POST', 'v1/logs', new ClosureRequestHandler(function (Request $request): Response {
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->logs[], ExportLogsServiceRequest::class, ExportLogsServiceResponse::class);
        }));

        /*
         * A route that always rejects the export with a non-retryable
         * status; used to test collector failure handling.
         */
        $router->addRoute('POST', 'v1/fail', new ClosureRequestHandler(fn(Request $request): Response => new Response(HttpStatus::INTERNAL_SERVER_ERROR)));
        $server->expose(new InternetAddress('127.0.0.1', 0));
        $server->start($router, new DefaultErrorHandler());

        $this->server = $server;

        /** @noinspection HttpUrlsUsage */
        $address = 'http://' . $server->getServers()[0]->getAddress()->toString();

        $this->env['OTEL_EXPORTER_OTLP_TRACES_PROTOCOL']  = 'http/json';
        $this->env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT']  = $address . '/v1/traces';
        $this->env['OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'] = $address . '/v1/metrics';
        $this->env['OTEL_EXPORTER_OTLP_LOGS_ENDPOINT']    = $address . '/v1/logs';
    }

    private static function captureRequestBody(Request $request, mixed &$slot, string $messageType, string $responseType): Response {
        $payload = $request->getBody()->buffer();

        if ($request->getHeader('content-encoding') === 'gzip' && extension_loaded('zlib')) {
            $payload = \gzdecode($payload) ?? throw new RuntimeException('Failed to gunzip request body');
        }

        $message = new $messageType();
        match ($request->getHeader('content-type')) {
            'application/x-protobuf' => $message->mergeFromString($payload),
            'application/json' => $message->mergeFromJsonString($payload, true),
            default => null,
        };
        $slot = $message->serializeToJsonString(PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS);

        $response = new $responseType();
        return match ($request->getHeader('content-type')) {
            'application/x-protobuf' => new Response(HttpStatus::OK, ['content-type' => 'application/x-protobuf'], $response->serializeToString()),
            'application/json' => new Response(HttpStatus::OK, ['content-type' => 'application/json'], $response->serializeToJsonString(\Google\Protobuf\PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS)),
            default => new Response(HttpStatus::OK),
        };
    }

    protected function tearDown(): void {
        $this->server->stop();
    }

    protected function runOTel(Closure $closure, string ...$env): string {
        $autoloadPath = Path::makeAbsolute('vendor/autoload.php', InstalledVersions::getRootPackage()['install_path']);

        $process = Process::start(
            command: [
                PHP_BINARY,
                __DIR__ . '/../executeSerializedClosure.php',
                $autoloadPath,
                \Opis\Closure\serialize($closure),
            ],
            environment: [
                'OTEL_PHP_AUTOLOAD_ENABLED' => 'true',
                ...$this->env,
                ...$env,
            ],
        );

        // pipe($process->getStderr(), getStderr());
        $output = buffer($process->getStdout());
        $stderr = buffer($process->getStderr());

        if ($exitCode = $process->join()) {
            throw new ProcessException(sprintf("Process exited with %d:\n%s", $exitCode, $stderr));
        }

        /*
         * A zero exit code is not sufficient: the SDK catches exceptions
         * during initialization (e.g. an invalid config file), logs them
         * to stderr and continues with a no-op SDK, which would make all
         * subsequent assertions vacuous.
         *
         * The message casing differs between SDKs ('Error during OpenTelemetry
         * initialization' vs 'Error during opentelemetry initialization'), so
         * compare case-insensitively.
         */
        self::assertStringNotContainsString(
            strtolower('Error during opentelemetry initialization'),
            strtolower($stderr),
            'The OTel SDK failed to initialize in the child process.',
        );

        return $output;
    }

    protected function runOTelConfig(string $config, Closure $closure, string ...$env): string {
        $tmpDir = sys_get_temp_dir();
        $configFile = $tmpDir . '/' . uniqid('otel-sdk-config-', true) . '.yaml';
        write($configFile, $config);
        try {
            return $this->runOTel($closure, ...$env, OTEL_CONFIG_FILE: $configFile);
        } finally {
            deleteFile($configFile);
        }
    }

    /*
     * =========================================================================
     * OTLP payload helpers
     *
     * The captured payloads ($this->traces / $this->metrics / $this->logs)
     * are JSON-serialized OTLP export requests. These helpers query them
     * with JSONPath.
     * =========================================================================
     */

    /**
     * @return list<mixed>
     */
    protected function path(string $payload, string $expression): array {
        if ($payload === '') {
            return [];
        }

        $result = (new JsonObject($payload))->get($expression);

        if ($result === null || $result === false) {
            return [];
        }

        return is_array($result)
            ? array_values($result)
            : [$result];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function spansInExport(string $payload): array {
        return $this->path(
            $payload,
            '$.resourceSpans[*].scopeSpans[*].spans[*]',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function logsInExport(string $payload): array {
        return $this->path(
            $payload,
            '$.resourceLogs[*].scopeLogs[*].logRecords[*]',
        );
    }

    /**
     * @return list<string>
     */
    protected function spanNames(string $payload): array {
        return $this->path(
            $payload,
            '$.resourceSpans[*].scopeSpans[*].spans[*].name',
        );
    }

    protected function resourceAttribute(
        string $payload,
        string $name,
        string $resourcePath = '$.resourceSpans[*].resource',
    ): mixed {
        $values = $this->path(
            $payload,
            sprintf(
                '%s.attributes[?(@.key == "%s")].value.*',
                $resourcePath,
                $name,
            ),
        );

        self::assertNotEmpty(
            $values,
            sprintf('Resource attribute "%s" was not found.', $name),
        );

        return $values[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function spanAttributes(string $spanName): array {
        return $this->path(
            $this->combinedTracePayload(),
            sprintf(
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "%s")].attributes[*]',
                $spanName,
            ),
        );
    }

    protected function spanAttribute(
        string $spanName,
        string $attribute,
    ): mixed {
        $values = $this->path(
            $this->combinedTracePayload(),
            sprintf(
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "%s")].attributes[?(@.key == "%s")].value.*',
                $spanName,
                $attribute,
            ),
        );

        self::assertNotEmpty(
            $values,
            sprintf(
                'Attribute "%s" was not found on span "%s".',
                $attribute,
                $spanName,
            ),
        );

        return $values[0];
    }

    protected function combinedTracePayload(): string {
        $resourceSpans = [];

        foreach ($this->traces as $payload) {
            $decoded = json_decode(
                $payload,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach ($decoded['resourceSpans'] ?? [] as $resourceSpan) {
                $resourceSpans[] = $resourceSpan;
            }
        }

        return json_encode(
            ['resourceSpans' => $resourceSpans],
            JSON_THROW_ON_ERROR,
        );
    }
    protected function assertSpanNames(array $expected): void {
        $actual = [];

        foreach ($this->traces as $payload) {
            $actual = [
                ...$actual,
                ...$this->spanNames($payload),
            ];
        }

        self::assertSame($expected, $actual);
    }

    protected function spanIdByName(string $name): string {
        $values = $this->path(
            $this->combinedTracePayload(),
            sprintf(
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "%s")].spanId',
                $name,
            ),
        );

        self::assertCount(1, $values);

        return $values[0];
    }

    protected function spanParentIdByName(string $name): string {
        $values = $this->path(
            $this->combinedTracePayload(),
            sprintf(
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "%s")].parentSpanId',
                $name,
            ),
        );

        self::assertCount(1, $values);

        return $values[0];
    }

    protected function scopeForMetric(
        string $payload,
        string $metricName,
    ): array {
        $scopeMetrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*]',
        );

        foreach ($scopeMetrics as $scopeMetric) {
            foreach ($scopeMetric['metrics'] ?? [] as $metric) {
                if (($metric['name'] ?? null) !== $metricName) {
                    continue;
                }

                self::assertArrayHasKey(
                    'scope',
                    $scopeMetric,
                    sprintf(
                        'Scope was not found for metric "%s".',
                        $metricName,
                    ),
                );

                return $scopeMetric['scope'];
            }
        }

        self::fail(sprintf(
            'Metric "%s" was not found in any instrumentation scope.',
            $metricName,
        ));
    }

    protected function metric(
        string $payload,
        string $name,
    ): array {
        $metrics = $this->path(
            $payload,
            sprintf(
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "%s")]',
                $name,
            ),
        );

        self::assertNotEmpty(
            $metrics,
            sprintf('Metric "%s" was not found.', $name),
        );

        return $metrics[0];
    }

    protected function dataPoints(
        string $payload,
        string $metricName,
        string $type = 'sum',
    ): array {
        return $this->path(
            $payload,
            sprintf(
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "%s")].%s.dataPoints[*]',
                $metricName,
                $type,
            ),
        );
    }

    protected function dataPoint(
        string $payload,
        string $metricName,
        array $attributes = [],
        string $type = 'sum',
    ): array {
        $dataPoints = $this->dataPoints(
            $payload,
            $metricName,
            $type,
        );

        foreach ($dataPoints as $dataPoint) {
            $actualAttributes = [];

            foreach ($dataPoint['attributes'] ?? [] as $attribute) {
                $actualAttributes[$attribute['key']] = $this->attributeValue(
                    $attribute['value'] ?? [],
                );
            }

            if ($actualAttributes === $attributes) {
                return $dataPoint;
            }
        }

        self::fail(sprintf(
            'Data point for metric "%s" with attributes %s was not found.',
            $metricName,
            json_encode($attributes, JSON_THROW_ON_ERROR),
        ));
    }

    protected function attributeValue(array $value): mixed
    {
        return $value['stringValue']
            ?? $value['intValue']
            ?? $value['doubleValue']
            ?? $value['boolValue']
            ?? $value['bytesValue']
            ?? null;
    }

    protected function lastMetricExport(): string
    {
        self::assertNotEmpty(
            $this->metrics,
            'No metric export was received.',
        );

        return $this->metrics[array_key_last($this->metrics)];
    }

}

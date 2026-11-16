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
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Closure;
use Composer\InstalledVersions;
use Google\Protobuf\PrintOptions;
use JsonPath\JsonObject;
use Opentelemetry\API\Globals;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceResponse;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceResponse;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTracePartialSuccess;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Throwable;
use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\pipe;
use function Amp\File\deleteFile;
use function Amp\File\write;
use function Amp\delay;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use const PHP_BINARY;

trait OTelEndpointTrait {

    /**
     * Size the /v1/oversized-response body reaches once decoded: above the
     * response size limit both SDKs use by default (4 MiB, the value the OTLP
     * specification recommends as default), and below the 10 MiB the tbachert
     * HTTP client bounds a decoded body with on its own, so the exporter is
     * the component that rejects it.
     */
    public const OVERSIZED_RESPONSE_SIZE = 5 * 1024 * 1024;

    /**
     * Size the /v1/within-limit-response body reaches once decoded, used as
     * control for OVERSIZED_RESPONSE_SIZE: same gzip-encoded response shape,
     * comfortably below the same limit.
     */
    public const WITHIN_LIMIT_RESPONSE_SIZE = 1024 * 1024;

    private HttpServer $server;

    /**
     * Base URL of the plain HTTP collector endpoint (http://127.0.0.1:port),
     * without a signal path.
     */
    public string $baseUrl = '';

    public array $traces = [];
    public array $metrics = [];
    public array $logs = [];

    /**
     * Request headers of every captured OTLP export request, in order.
     *
     * @var list<array<string, string>>
     */
    public array $requestHeaders = [];

    /**
     * Stderr output of the most recent child process run.
     */
    public string $lastStderr = '';

    /**
     * Number of requests received by the /v1/slow route.
     */
    public int $slowRequests = 0;

    /**
     * Number of requests received by the /v1/fail route.
     */
    public int $failRequests = 0;

    /**
     * Number of requests received by the /v1/flaky route (503 on the first
     * request, then captured like /v1/traces).
     */
    public int $flakyRequests = 0;

    /**
     * Number of requests received by the /v1/throttle route (429 with a
     * Retry-After header on the first request, then captured like
     * /v1/traces).
     */
    public int $throttleRequests = 0;

    /**
     * Number of requests received by the /v1/oversized-response route.
     */
    public int $oversizedResponseRequests = 0;

    /**
     * Bytes actually sent by the /v1/oversized-response route, i.e. the
     * gzip-compressed response body; it exceeds the response size limit only
     * once the client has decoded it.
     */
    public int $oversizedResponseWireBytes = 0;

    /**
     * Number of requests received by the /v1/within-limit-response route, the
     * control for /v1/oversized-response.
     */
    public int $withinLimitResponseRequests = 0;

    /**
     * Arrival times (microtime) of the captured export requests, in order.
     */
    public array $requestTimes = [];

    public array $env = [];

    protected function setUp(): void {
        $this->requestHeaders = [];
        $this->requestTimes = [];

        $server = SocketHttpServer::createForDirectAccess(new NullLogger());

        $router = new Router($server, new NullLogger(), new DefaultErrorHandler());
        $router->addRoute('POST', 'v1/traces', new ClosureRequestHandler(function (Request $request): Response {
            $this->requestTimes[] = microtime(true);
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->traces[], ExportTraceServiceRequest::class, ExportTraceServiceResponse::class);
        }));
        $router->addRoute('POST', 'v1/metrics', new ClosureRequestHandler(function (Request $request): Response {
            $this->requestTimes[] = microtime(true);
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->metrics[], ExportMetricsServiceRequest::class, ExportMetricsServiceResponse::class);
        }));
        $router->addRoute('POST', 'v1/logs', new ClosureRequestHandler(function (Request $request): Response {
            $this->requestTimes[] = microtime(true);
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->logs[], ExportLogsServiceRequest::class, ExportLogsServiceResponse::class);
        }));

        /*
         * A route that answers with a valid success response whose body expands
         * past the response size limit *after decompression*: the padding is
         * gzip-compressed on the wire, so the bytes sent stay far below the
         * limit, and only the decoded body can exceed it. The request is read
         * but not captured, because the exporter never sees the answer.
         */
        $router->addRoute('POST', 'v1/oversized-response', new ClosureRequestHandler(function (Request $request): Response {
            $this->oversizedResponseRequests++;
            $this->requestHeaders[] = $request->getHeaders();

            $ignored = null;
            $wireBytes = 0;
            $response = self::captureRequestBody($request, $ignored, ExportTraceServiceRequest::class, ExportTraceServiceResponse::class, self::OVERSIZED_RESPONSE_SIZE, $wireBytes);
            $this->oversizedResponseWireBytes = $wireBytes;

            return $response;
        }));

        /*
         * The control for /v1/oversized-response: the same response shape, kept
         * below the size limit, and captured like /v1/traces.
         */
        $router->addRoute('POST', 'v1/within-limit-response', new ClosureRequestHandler(function (Request $request): Response {
            $this->withinLimitResponseRequests++;
            $this->requestTimes[] = microtime(true);
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->traces[], ExportTraceServiceRequest::class, ExportTraceServiceResponse::class, self::WITHIN_LIMIT_RESPONSE_SIZE);
        }));

        /*
         * The root path: per the OTLP specification, a per-signal endpoint
         * without a path part sends exports to the root, not to /v1/<signal>.
         */
        $router->addRoute('POST', '/', new ClosureRequestHandler(function (Request $request): Response {
            $this->requestTimes[] = microtime(true);
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->traces[], ExportTraceServiceRequest::class, ExportTraceServiceResponse::class);
        }));

        /*
         * A route that always rejects the export with a non-retryable
         * status; used to test collector failure handling.
         */
        $router->addRoute('POST', 'v1/fail', new ClosureRequestHandler(function (Request $request): Response {
            $this->failRequests++;

            return new Response(HttpStatus::INTERNAL_SERVER_ERROR);
        }));

        /*
         * A route that rejects the first export with a retryable status and
         * accepts subsequent ones; used to test exporter retry behavior.
         */
        $router->addRoute('POST', 'v1/flaky', new ClosureRequestHandler(function (Request $request): Response {
            $this->flakyRequests++;

            if ($this->flakyRequests === 1) {
                return new Response(HttpStatus::SERVICE_UNAVAILABLE);
            }

            $this->requestTimes[] = microtime(true);
            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->traces[], ExportTraceServiceRequest::class, ExportTraceServiceResponse::class);
        }));

        /*
         * A route that throttles the first export (429 + Retry-After) and
         * accepts subsequent ones; used to test throttling response handling.
         */
        $router->addRoute('POST', 'v1/throttle', new ClosureRequestHandler(function (Request $request): Response {
            $this->throttleRequests++;
            $this->requestTimes[] = microtime(true);

            if ($this->throttleRequests === 1) {
                return new Response(HttpStatus::TOO_MANY_REQUESTS, ['retry-after' => '6']);
            }

            $this->requestHeaders[] = $request->getHeaders();

            return self::captureRequestBody($request, $this->traces[], ExportTraceServiceRequest::class, ExportTraceServiceResponse::class);
        }));

        /*
         * A route that delays its response; used to test exporter timeout
         * handling.
         */
        $router->addRoute('POST', 'v1/slow', new ClosureRequestHandler(function (Request $request): Response {
            $this->slowRequests++;

            /*
             * Delay the response beyond the export timeout used by the test
             * (Amp\delay accepts fractional seconds, so this stays fast).
             */
            delay(0.5);

            return new Response(HttpStatus::OK);
        }));
        $server->expose(new InternetAddress('127.0.0.1', 0));
        $server->start($router, new DefaultErrorHandler());

        $this->server = $server;

        /** @noinspection HttpUrlsUsage */
        $address = 'http://' . $server->getServers()[0]->getAddress()->toString();

        $this->baseUrl = $address;

        $this->env['OTEL_EXPORTER_OTLP_PROTOCOL']         = 'http/json';
        $this->env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT']  = $address . '/v1/traces';
        $this->env['OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'] = $address . '/v1/metrics';
        $this->env['OTEL_EXPORTER_OTLP_LOGS_ENDPOINT']    = $address . '/v1/logs';
    }

    /**
     * Captures the request body into the given slot as a JSON string.
     *
     * Note on id encoding: OTLP/JSON messages carry trace and span ids as
     * lowercase hex strings, an explicit deviation from the canonical
     * proto3 JSON mapping (which would use base64 for bytes fields). The
     * capture round-trips both wire encodings through the protobuf library
     * (binary bodies are parsed, JSON bodies are merged), and bytes fields
     * survive that round trip unchanged: a capture shows whatever
     * representation the exporter sent, hex for OTLP/JSON and base64 of
     * the raw bytes for OTLP/protobuf. Compare ids across signals with
     * normalizeId().
     *
     * With $decodedResponseSize, the success response is padded so that its
     * body reaches that size once decoded, and is gzip-encoded on the wire.
     */
    private static function captureRequestBody(Request $request, mixed &$slot, string $messageType, string $responseType, ?int $decodedResponseSize = null, ?int &$responseWireBytes = null): Response {
        $payload = $request->getBody()->buffer();

        if ($request->getHeader('content-encoding') === 'gzip' && extension_loaded('zlib')) {
            $payload = \gzdecode($payload) ?? throw new RuntimeException('Failed to gunzip request body');
        }

        $message = new $messageType();
        match ($request->getHeader('content-type')) {
            // Raised recursion limit: deeply nested attribute values (truncated
            // at the spec's default depth of 64) must round-trip through the
            // capture endpoint. The limit argument is only accepted by the C
            // extension since protobuf 5.36
            // (https://github.com/protocolbuffers/protobuf/pull/27874); on
            // older versions fall back to the default limit.
            'application/x-protobuf' => ($v = phpversion('protobuf')) === false || version_compare($v, '5.36.0', '>=')
                ? $message->mergeFromString($payload, 65535)
                : $message->mergeFromString($payload),
            'application/json' => $message->mergeFromJsonString($payload, true),
            default => null,
        };
        $slot = $message->serializeToJsonString(PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS);

        $response = new $responseType();

        if ($decodedResponseSize === null) {
            return match ($request->getHeader('content-type')) {
                'application/x-protobuf' => new Response(HttpStatus::OK, ['content-type' => 'application/x-protobuf'], $response->serializeToString()),
                'application/json' => new Response(HttpStatus::OK, ['content-type' => 'application/json'], $response->serializeToJsonString(\Google\Protobuf\PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS)),
                default => new Response(HttpStatus::OK),
            };
        }

        // Encode the answer like the request, so that it is hydrated.
        $json = $request->getHeader('content-type') === 'application/json';
        self::padTraceResponse($response, $json, $decodedResponseSize);
        $body = $json
            ? $response->serializeToJsonString(PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS)
            : $response->serializeToString();

        /*
         * Content-Encoding is written by the route itself: the official
         * transport asks for "identity", so the server's compression
         * middleware would leave the response alone.
         */
        return self::gzipResponse($json ? 'application/json' : 'application/x-protobuf', $body, $responseWireBytes);
    }

    /**
     * Pad the partial-success error message of a success response until its
     * body reaches exactly $size bytes in the encoding the request used.
     */
    private static function padTraceResponse(ExportTraceServiceResponse $response, bool $json, int $size): void {
        $partialSuccess = new ExportTracePartialSuccess();
        $response->setPartialSuccess($partialSuccess);

        $encode = static fn (): string => $json
            ? $response->serializeToJsonString(PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS)
            : $response->serializeToString();

        // The framing around the error message field is not padding; measure
        // it with a first pass, then pad to what is left.
        $length = max(0, $size - strlen($encode()));
        $partialSuccess->setErrorMessage(str_repeat('A', $length));
        $partialSuccess->setErrorMessage(str_repeat('A', $length + $size - strlen($encode())));

        if (($actual = strlen($encode())) !== $size) {
            throw new RuntimeException(sprintf('Padded response body is %d bytes, expected %d', $actual, $size));
        }
    }

    /**
     * A response whose body is gzip-encoded, with the encoding written
     * explicitly so that the client decodes it however it asked for it.
     */
    private static function gzipResponse(string $contentType, string $body, ?int &$wireBytes = null): Response {
        $encoded = \gzencode($body, 9) ?? throw new RuntimeException('Failed to gzip response body');
        $wireBytes = strlen($encoded);

        return new Response(HttpStatus::OK, [
            'content-type' => $contentType,
            'content-encoding' => 'gzip',
        ], $encoded);
    }

    /**
     * Normalizes a captured trace or span id to lowercase hex, whatever the
     * wire encoding of the export that produced the capture: OTLP/JSON ids
     * are already hex, while OTLP/protobuf ids are base64 of the raw bytes
     * (see the note on id encoding in captureRequestBody()).
     */
    protected static function normalizeId(string $id): string {
        return preg_match('/^(?:[0-9a-f]{16}|[0-9a-f]{32})$/', strtolower($id)) === 1
            ? strtolower($id)
            : bin2hex(base64_decode($id));
    }

    protected function tearDown(): void {
        $this->server->stop();
    }

    protected function runOTel(Closure $closure, string ...$env): string {
        return $this->runOTelInternal($closure, false, ...$env);
    }

    /**
     * Like runOTel, but expects the SDK initialization in the child process
     * to fail: the init error must be present on stderr.
     */
    protected function runOTelExpectingInitError(Closure $closure, string ...$env): string {
        return $this->runOTelInternal($closure, true, ...$env);
    }

    private function runOTelInternal(Closure $closure, bool $expectInitError, string ...$env): string {
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

        /*
         * Both child streams are drained concurrently. Reading stdout to EOF
         * first deadlocks whenever the child fills the stderr pipe buffer
         * (64 KiB on Linux): the child blocks writing, this process blocks
         * reading the other pipe.
         */
        $stdoutFuture = async(static fn (): string => buffer($process->getStdout()));
        $stderrFuture = async(static fn (): string => buffer($process->getStderr()));
        try {
            $output = $stdoutFuture->await();
        } finally {
            $stderr = $stderrFuture->await();
        }

        $this->lastStderr = $stderr;

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
        if ($expectInitError) {
            self::assertStringContainsString(
                strtolower('Error during opentelemetry initialization'),
                strtolower($stderr),
                'Expected the OTel SDK to fail to initialize in the child process.',
            );
        } else {
            self::assertStringNotContainsString(
                strtolower('Error during opentelemetry initialization'),
                strtolower($stderr),
                'The OTel SDK failed to initialize in the child process.',
            );
        }

        return $output;
    }

    protected function runOTelConfig(string $config, Closure $closure, string ...$env): string {
        return $this->runOTelConfigInternal($config, $closure, false, ...$env);
    }

    /**
     * Like runOTelConfig, but expects the SDK initialization in the child
     * process to fail: the init error must be present on stderr.
     */
    protected function runOTelConfigExpectingInitError(string $config, Closure $closure, string ...$env): string {
        return $this->runOTelConfigInternal($config, $closure, true, ...$env);
    }

    private function runOTelConfigInternal(string $config, Closure $closure, bool $expectInitError, string ...$env): string {
        $tmpDir = sys_get_temp_dir();
        $configFile = $tmpDir . '/' . uniqid('otel-sdk-config-', true) . '.yaml';
        write($configFile, $config);
        try {
            return $expectInitError
                ? $this->runOTelExpectingInitError($closure, ...$env, OTEL_CONFIG_FILE: $configFile)
                : $this->runOTel($closure, ...$env, OTEL_CONFIG_FILE: $configFile);
        } finally {
            deleteFile($configFile);
        }
    }

    /**
     * Dials a plaintext gRPC endpoint and asserts that the first bytes on the
     * connection are the HTTP/2 connection preface. This is the strongest
     * verification possible for plaintext (h2c prior knowledge) gRPC in this
     * environment: no gRPC server here can serve prior-knowledge h2c, so full
     * round trips cannot be exercised. The child process is killed as soon as
     * the dial has been captured.
     */
    protected function assertPlaintextGrpcDial(Closure $configure, ?Closure $telemetry = null): void {
        $this->assertGrpcDial($configure, "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n", $telemetry);
    }

    /**
     * Starts a child process that is expected to dial a gRPC endpoint and
     * asserts the transport on the wire: the plaintext HTTP/2 connection
     * preface or the TLS ClientHello record. See assertPlaintextGrpcDial for
     * why this stops at the dial.
     */
    protected function assertGrpcDial(Closure $configure, string $expectedPrefix, ?Closure $telemetry = null): void {
        $server = (new ResourceServerSocketFactory())->listen(new InternetAddress('127.0.0.1', 0));
        $port = $server->getAddress()->getPort();

        $captured = new DeferredFuture();
        async(static function () use ($server, $captured): void {
            try {
                $connection = $server->accept();

                $bytes = '';
                while (strlen($bytes) < 24 && null !== ($chunk = $connection->read())) {
                    $bytes .= $chunk;
                }

                $captured->complete($bytes);
            } catch (Throwable $exception) {
                $captured->error($exception);
            }
        });

        $autoloadPath = Path::makeAbsolute('vendor/autoload.php', InstalledVersions::getRootPackage()['install_path']);
        $process = Process::start(
            command: [
                PHP_BINARY,
                __DIR__ . '/../executeSerializedClosure.php',
                $autoloadPath,
                \Opis\Closure\serialize($telemetry ?? static function (): void {
                    Globals::tracerProvider()->getTracer('grpc-test')
                        ->spanBuilder('grpc-plaintext-span')
                        ->startSpan()
                        ->end();
                }),
            ],
            environment: ['OTEL_PHP_AUTOLOAD_ENABLED' => 'true', ...$this->env, ...$configure($port)],
        );

        $timeout = 15;

        try {
            /*
             * The first bytes on a plaintext h2c connection are the HTTP/2
             * connection preface; a TLS client would send a ClientHello
             * record (0x16 0x03) instead. (Only the prefix is compared:
             * read() may return a larger chunk that also contains further
             * frames or records.)
             */
            self::assertStringStartsWith(
                $expectedPrefix,
                (string)$captured->getFuture()->await(new TimeoutCancellation($timeout)),
            );
        } catch (CancelledException | TimeoutException $exception) {
            /*
             * amphp v3 reports an expired TimeoutCancellation as a
             * CancelledException whose previous exception is the
             * TimeoutException (vendor/amphp/amp/src/Internal/Cancellable.php),
             * so both types mean the same thing here: no bytes arrived on the
             * listening socket before the deadline. (A TimeoutException can be
             * thrown directly by an amphp version that does not wrap it.)
             *
             * Genuine assertion failures are deliberately not caught: a dial
             * that did happen must report the transport it put on the wire.
             */
            $cause = $exception->getPrevious() ?? $exception;

            // The TimeoutException message continues with a stack trace on the
            // following lines; the first line names the reason.
            $reason = trim(explode("\n", $cause->getMessage())[0]);

            self::fail(sprintf(
                'The gRPC client did not dial the endpoint within %d seconds (%s: %s)',
                $timeout,
                $cause::class,
                $reason,
            ));
        } finally {
            try {
                $process->kill();
            } catch (Throwable) {
                // The process may have exited on its own already.
            }

            $server->close();
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
     * Evaluates a JSONPath query and returns its first match.
     *
     * Unlike path(), which hands back the - possibly empty - list of
     * matches, this asserts that the query matched at all: indexing an empty
     * list yields null, which surfaces as a TypeError on the next assertion
     * and hides the actual cause, namely that the export did not contain the
     * data point the test was looking for.
     *
     * @param string $expected What the test expected to find, for the
     *        failure message (e.g. the instrument and data point kind).
     */
    protected function pathFirst(string $payload, string $expression, string $expected): mixed {
        $matches = $this->path($payload, $expression);

        self::assertNotEmpty(
            $matches,
            sprintf(
                'Expected %s, but the query %s matched nothing in the captured export.',
                $expected,
                $expression,
            ),
        );

        return $matches[0];
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
     * Entity references exported with the resource: type plus keys into the
     * resource attributes (the payload carries references, not full entities).
     *
     * @return list<array<string, mixed>>
     */
    protected function resourceEntityRefs(
        string $payload,
        string $resourcePath = '$.resourceSpans[*].resource',
    ): array {
        return $this->path(
            $payload,
            sprintf('%s.entityRefs[*]', $resourcePath),
        );
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


    /**
     * Sort data points collected from multiple exports by collection time.
     *
     * Exports may arrive out of order (the exporter uses a connection pool),
     * so tests asserting on value sequences must sort first.
     */
    protected function sortByCollectionTime(array $dataPoints): array {
        usort(
            $dataPoints,
            static fn(array $a, array $b): int => (
                (int) ($a['timeUnixNano'] ?? 0)
            ) <=> (
                (int) ($b['timeUnixNano'] ?? 0)
            ),
        );

        return $dataPoints;
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

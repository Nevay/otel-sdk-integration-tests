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

    public array $env = [];

    protected function setUp(): void {
        $server = SocketHttpServer::createForDirectAccess(new NullLogger());

        $router = new Router($server, new NullLogger(), new DefaultErrorHandler());
        $router->addRoute('POST', 'v1/traces', new ClosureRequestHandler(fn(Request $request): Response => self::captureRequestBody($request, $this->traces[], ExportTraceServiceRequest::class, ExportTraceServiceResponse::class)));
        $router->addRoute('POST', 'v1/metrics', new ClosureRequestHandler(fn(Request $request): Response => self::captureRequestBody($request, $this->metrics[], ExportMetricsServiceRequest::class, ExportMetricsServiceResponse::class)));
        $router->addRoute('POST', 'v1/logs', new ClosureRequestHandler(fn(Request $request): Response => self::captureRequestBody($request, $this->logs[], ExportLogsServiceRequest::class, ExportLogsServiceResponse::class)));
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

        if ($exitCode = $process->join()) {
            throw new ProcessException(sprintf("Process exited with %d:\n%s", $exitCode, buffer($process->getStderr())));
        }

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
}
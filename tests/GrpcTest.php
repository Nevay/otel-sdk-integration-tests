<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Future;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\Trailers;
use Amp\Http\HttpStatus;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerTlsContext;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceResponse;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceResponse;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Exports over gRPC. The SDK's gRPC exporters do not use the PHP grpc
 * extension; they speak length-prefixed protobuf frames (gRPC over HTTP/2)
 * with the amphp HTTP client, so this class serves them from a TLS capture
 * server with the HTTP/2 driver enabled (plaintext h2c prior knowledge is
 * not supported by the server).
 */
final class GrpcTest extends TestCase {
    use OTelEndpointTrait;

    private const CERT = __DIR__ . '/fixtures/tls/cert.pem';
    private const KEY  = __DIR__ . '/fixtures/tls/key.pem';

    /** @var list<SocketHttpServer> */
    private array $servers = [];
    private string $grpcBaseUrl = '';

    /** Compression flag of the most recent captured gRPC frame. */
    private int $lastGrpcCompressed = 0;

    protected function setUp(): void {
        parent::setUp();

        [$server, $this->grpcBaseUrl] = $this->createGrpcCaptureServer('0');
    }

    protected function tearDown(): void {
        foreach ($this->servers as $server) {
            $server->stop();
        }

        parent::tearDown();
    }

    /**
     * @return array{SocketHttpServer, string} [server, base URL]
     */
    private function createGrpcCaptureServer(string $grpcStatus): array {
        $server = SocketHttpServer::createForDirectAccess(new NullLogger());
        $router = new Router($server, new NullLogger(), new DefaultErrorHandler());

        $router->addRoute('POST', 'opentelemetry.proto.collector.trace.v1.TraceService/Export', new ClosureRequestHandler(function (Request $request) use ($grpcStatus): Response {
            return self::captureGrpcBody(
                $request,
                $this->traces[],
                $this->lastGrpcCompressed,
                ExportTraceServiceRequest::class,
                ExportTraceServiceResponse::class,
                $grpcStatus,
            );
        }));

        $router->addRoute('POST', 'opentelemetry.proto.collector.metrics.v1.MetricsService/Export', new ClosureRequestHandler(function (Request $request) use ($grpcStatus): Response {
            return self::captureGrpcBody(
                $request,
                $this->metrics[],
                $this->lastGrpcCompressed,
                ExportMetricsServiceRequest::class,
                ExportMetricsServiceResponse::class,
                $grpcStatus,
            );
        }));

        $router->addRoute('POST', 'opentelemetry.proto.collector.logs.v1.LogsService/Export', new ClosureRequestHandler(function (Request $request) use ($grpcStatus): Response {
            return self::captureGrpcBody(
                $request,
                $this->logs[],
                $this->lastGrpcCompressed,
                ExportLogsServiceRequest::class,
                ExportLogsServiceResponse::class,
                $grpcStatus,
            );
        }));

        $tlsContext = (new ServerTlsContext())
            ->withDefaultCertificate(new Certificate(self::CERT, self::KEY));

        $server->expose(
            new InternetAddress('127.0.0.1', 0),
            (new BindContext())->withTlsContext($tlsContext),
        );

        $server->start($router, new DefaultErrorHandler());
        $this->servers[] = $server;

        return [$server, 'https://127.0.0.1:' . $server->getServers()[0]->getAddress()->getPort()];
    }

    /**
     * Unpacks a single gRPC frame (5-byte prefix + protobuf payload),
     * captures the message as JSON, and responds with an empty framed
     * response plus the configured grpc-status trailer.
     */
    private static function captureGrpcBody(
        Request $request,
        mixed &$slot,
        int &$compressed,
        string $messageType,
        string $responseType,
        string $grpcStatus,
    ): Response {
        $body = $request->getBody()->buffer();

        $prefix = unpack('Ccompressed/Nlength', substr($body, 0, 5));
        $payload = substr($body, 5);
        $compressed = $prefix['compressed'];

        if ($prefix['compressed']) {
            $payload = \gzdecode($payload) ?? throw new RuntimeException('Failed to gunzip gRPC frame');
        }

        $message = new $messageType();
        $message->mergeFromString($payload);
        $slot = $message->serializeToJsonString(\Google\Protobuf\PrintOptions::ALWAYS_PRINT_ENUMS_AS_INTS);

        $responseBody = (new $responseType())->serializeToString();
        $frame = pack('CN', 0, strlen($responseBody)) . $responseBody;

        return new Response(
            HttpStatus::OK,
            ['content-type' => 'application/grpc+proto'],
            $frame,
            new Trailers(Future::complete(['grpc-status' => $grpcStatus]), ['grpc-status']),
        );
    }

    #[Group('env'), Group('traces')]
    public function testEnvGrpcProtocolExportsSpans(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('grpc-test')
                    ->spanBuilder('grpc-span')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=grpc',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->grpcBaseUrl,
            'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=' . self::CERT,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['grpc-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('config-file'), Group('traces')]
    public function testConfigFileGrpcExporterExportsSpans(): void {
        $caFile = self::CERT;

        $this->runOTelConfig(<<<YAML
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_grpc:
                        endpoint: {$this->grpcBaseUrl}
                        tls:
                          ca_file: {$caFile}
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('grpc-test')
                ->spanBuilder('grpc-config-span')
                ->startSpan()
                ->end();
        });

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['grpc-config-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('env'), Group('traces')]
    public function testEnvGrpcGzipCompressionIsApplied(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('grpc-test')
                    ->spanBuilder('grpc-gzip-span')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=grpc',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->grpcBaseUrl,
            'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=' . self::CERT,
            'OTEL_EXPORTER_OTLP_TRACES_COMPRESSION=gzip',
        );

        /*
         * The frame arrives with the compression flag set; after
         * decompression the span is intact.
         */
        self::assertSame(1, $this->lastGrpcCompressed);

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['grpc-gzip-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('env'), Group('traces'), Group('metrics'), Group('logs')]
    public function testGenericGrpcProtocolAppliesToAllSignals(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('grpc-test')
                    ->spanBuilder('grpc-generic-span')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('grpc-test')
                    ->createCounter('generic.counter', 'requests', 'a probe counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('grpc-test')
                    ->emit(new LogRecord('generic log record'));
            },
            'OTEL_EXPORTER_OTLP_PROTOCOL=grpc',
            'OTEL_EXPORTER_OTLP_ENDPOINT=' . $this->grpcBaseUrl,
            'OTEL_EXPORTER_OTLP_CERTIFICATE=' . self::CERT,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['grpc-generic-span'],
            $this->spanNames($this->traces[0]),
        );

        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);
    }

    #[Group('env'), Group('traces')]
    public function testGrpcErrorStatusIsReportedWithoutRetry(): void {
        /*
         * The second server answers every export with grpc-status 3
         * (InvalidArgument), which is not retryable: the request is made
         * exactly once and the failure is reported.
         */
        [, $errorBaseUrl] = $this->createGrpcCaptureServer('3');

        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('grpc-test')
                    ->spanBuilder('grpc-error-span')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=grpc',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $errorBaseUrl,
            'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=' . self::CERT,
        );

        self::assertCount(1, $this->traces);
        self::assertStringContainsString(
            'invalidargument',
            strtolower($this->lastStderr),
        );
    }
}

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
use Amp\DeferredFuture;
use Amp\Process\Process;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerTlsContext;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Closure;
use Composer\InstalledVersions;
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
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Throwable;
use function Amp\async;

/**
 * Exports over gRPC. The SDK's gRPC exporters do not use the PHP grpc
 * extension; they speak length-prefixed protobuf frames (gRPC over HTTP/2)
 * with the amphp HTTP client, so this class serves them from a TLS capture
 * server with the HTTP/2 driver enabled.
 *
 * Plaintext endpoints (http:// and tls.insecure) use h2c prior knowledge,
 * which no gRPC server in this environment can serve: the amphp HTTP server
 * only speaks HTTP/2 over TLS (ALPN) or via the opt-in h2c UPGRADE
 * mechanism, and a C-core based server (PHP grpc extension) cannot complete
 * an HTTP/2 exchange at all in this environment (it resets every connection
 * right after the preface, regardless of client). The plaintext tests
 * therefore verify the dial itself — that the exporter connects in plaintext
 * and speaks the HTTP/2 preface — against a raw TCP listener. End-to-end
 * plaintext export has been verified manually against a reference collector
 * (opentelemetry-collector).
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
    public function testEnvGrpcWithoutTls(): void {
        $this->assertPlaintextGrpcDial(static fn (int $port): array => [
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL' => 'grpc',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => "http://127.0.0.1:$port",
        ]);
    }

    #[Group('config-file'), Group('traces')]
    public function testConfigFileInsecureGrpcExporter(): void {
        $configFile = null;

        $this->assertPlaintextGrpcDial(static function (int $port) use (&$configFile): array {
            $configFile = sys_get_temp_dir() . '/otel-test-grpc-insecure-' . uniqid() . '.yaml';
            file_put_contents($configFile, <<<YAML
                file_format: "1.2"

                tracer_provider:
                  processors:
                    - batch:
                        exporter:
                          otlp_grpc:
                            endpoint: 127.0.0.1:$port
                            tls:
                              insecure: true
            YAML);

            return ['OTEL_CONFIG_FILE' => $configFile];
        });

        @unlink($configFile ?? '');
    }

    /**
     * Runs the SDK in a child process against a raw TCP listener and asserts
     * that the first bytes on the wire are the HTTP/2 connection preface —
     * i.e. the gRPC exporter dialed the endpoint in plaintext (h2c prior
     * knowledge, no TLS handshake).
     *
     * A full export cannot be verified for plaintext endpoints in this
     * environment (see the class docblock); the child process is killed as
     * soon as the dial has been captured, because without a working h2c peer
     * it would only retry the export for ~30 seconds.
     */
    private function assertPlaintextGrpcDial(Closure $configure): void {
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
                \Opis\Closure\serialize(static function (): void {
                    Globals::tracerProvider()->getTracer('grpc-test')
                        ->spanBuilder('grpc-plaintext-span')
                        ->startSpan()
                        ->end();
                }),
            ],
            environment: ['OTEL_PHP_AUTOLOAD_ENABLED' => 'true', ...$this->env, ...$configure($port)],
        );

        try {
            /*
             * The first bytes on a plaintext h2c connection are the HTTP/2
             * connection preface; a TLS client would send a ClientHello
             * record instead. (Only the preface is compared: read() may
             * return a larger chunk that also contains the SETTINGS frame.)
             */
            self::assertSame(
                "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n",
                substr((string)$captured->getFuture()->await(new TimeoutCancellation(15)), 0, 24),
            );
        } catch (TimeoutException) {
            self::fail('The gRPC exporter did not dial the plaintext endpoint');
        } finally {
            try {
                $process->kill();
            } catch (Throwable) {
                // The process may have exited on its own already.
            }

            $server->close();
        }
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

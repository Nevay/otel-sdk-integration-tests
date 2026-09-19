<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Exports over HTTPS to collectors with self-signed certificates. The
 * suite's regular capture server runs in plain HTTP, so this class exposes
 * two additional TLS-secured endpoints that capture into the same slots:
 * one that only verifies the client against the system CA bundle (i.e.
 * rejects our self-signed CA unless configured) and one that requires a
 * dedicated client certificate (mutual TLS).
 */
#[Group('spec')]
final class TlsTest extends TestCase {
    use OTelEndpointTrait;

    private const CERT = __DIR__ . '/fixtures/tls/cert.pem';
    private const KEY  = __DIR__ . '/fixtures/tls/key.pem';

    /**
     * A second, distinct self-signed pair used as the mTLS client
     * certificate: because it differs from the CA/server fixture, a
     * transport that presents the wrong file (or none) fails the
     * handshake instead of silently passing.
     */
    private const CLIENT_CERT = __DIR__ . '/fixtures/tls/client_cert.pem';
    private const CLIENT_KEY  = __DIR__ . '/fixtures/tls/client_key.pem';

    /** @var list<SocketHttpServer> */
    private array $servers = [];
    private string $tlsBaseUrl = '';
    private string $mtlsBaseUrl = '';

    protected function setUp(): void {
        parent::setUp();

        [$server, $this->tlsBaseUrl] = $this->createTlsCaptureServer(false);
        [$server, $this->mtlsBaseUrl] = $this->createTlsCaptureServer(true);
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
    private function createTlsCaptureServer(bool $requireClientCertificate): array {
        /*
         * The mTLS endpoint disables HTTP/2: with the default ALPN list
         * (h2 first) a rejected client certificate surfaces on the client
         * as an opaque "closed before HTTP/2 settings" error instead of
         * the certificate verification failure.
         */
        $driverFactory = new DefaultHttpDriverFactory(
            new NullLogger(),
            http2Enabled: !$requireClientCertificate,
        );

        $server = SocketHttpServer::createForDirectAccess(
            new NullLogger(),
            httpDriverFactory: $driverFactory,
        );
        $router = new Router($server, new NullLogger(), new DefaultErrorHandler());
        $router->addRoute('POST', 'v1/traces', new ClosureRequestHandler(function (Request $request): Response {
            return self::captureRequestBody(
                $request,
                $this->traces[],
                ExportTraceServiceRequest::class,
                ExportTraceServiceResponse::class,
            );
        }));
        $router->addRoute('POST', 'v1/metrics', new ClosureRequestHandler(function (Request $request): Response {
            return self::captureRequestBody(
                $request,
                $this->metrics[],
                ExportMetricsServiceRequest::class,
                ExportMetricsServiceResponse::class,
            );
        }));
        $router->addRoute('POST', 'v1/logs', new ClosureRequestHandler(function (Request $request): Response {
            return self::captureRequestBody(
                $request,
                $this->logs[],
                ExportLogsServiceRequest::class,
                ExportLogsServiceResponse::class,
            );
        }));

        $tlsContext = (new ServerTlsContext())
            ->withDefaultCertificate(new Certificate(self::CERT, self::KEY));

        if ($requireClientCertificate) {
            /*
             * Peer name verification must be disabled: with an empty
             * peer_name PHP compares the client certificate's CN against
             * an empty string and fails the handshake.
             */
            $tlsContext = $tlsContext
                ->withPeerVerification()
                ->withoutPeerNameVerification()
                ->withCaFile(self::CLIENT_CERT);
        }

        $server->expose(
            new InternetAddress('127.0.0.1', 0),
            (new BindContext())->withTlsContext($tlsContext),
        );

        $server->start($router, new DefaultErrorHandler());
        $this->servers[] = $server;

        return [$server, 'https://127.0.0.1:' . $server->getServers()[0]->getAddress()->getPort()];
    }

    #[Group('env'), Group('traces')]
    public function testEnvCertificateTrustsSelfSignedCollector(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('tls-test')
                    ->spanBuilder('tls-span')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->tlsBaseUrl . '/v1/traces',
            'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=' . self::CERT,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['tls-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('env'), Group('metrics')]
    public function testMetricsCertificateEnvVarTrustsSelfSignedCollector(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()->getMeter('tls-test')
                    ->createCounter('tls-metric')
                    ->add(1);
            },
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=' . $this->tlsBaseUrl . '/v1/metrics',
            'OTEL_EXPORTER_OTLP_METRICS_CERTIFICATE=' . self::CERT,
        );

        /*
         * The per-signal certificate variable is honoured for metrics: the
         * self-signed collector is trusted and the data point is exported.
         */
        self::assertCount(1, $this->metrics);
    }

    #[Group('env'), Group('logs')]
    public function testLogsCertificateEnvVarTrustsSelfSignedCollector(): void {
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()->getLogger('tls-test')
                    ->logRecordBuilder()
                    ->setBody('tls-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=' . $this->tlsBaseUrl . '/v1/logs',
            'OTEL_EXPORTER_OTLP_LOGS_CERTIFICATE=' . self::CERT,
        );

        /*
         * The per-signal certificate variable is honoured for logs: the
         * self-signed collector is trusted and the record is exported.
         */
        self::assertCount(1, $this->logs);
    }

    #[Group('config-file'), Group('traces')]
    public function testConfigFileCaFileTrustsSelfSignedCollector(): void {
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        tls:
                          ca_file: ${OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE}
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('tls-test')
                ->spanBuilder('tls-config-span')
                ->startSpan()
                ->end();
        },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->tlsBaseUrl . '/v1/traces',
            'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=' . self::CERT,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['tls-config-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('config-file'), Group('metrics')]
    public function testConfigFileCaFileTrustsSelfSignedMetricsCollector(): void {
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        tls:
                          ca_file: ${OTEL_EXPORTER_OTLP_METRICS_CERTIFICATE}
        YAML, static function (): void {
            Globals::meterProvider()->getMeter('tls-test')
                ->createCounter('tls-config-metric')
                ->add(1);
        },
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=' . $this->tlsBaseUrl . '/v1/metrics',
            'OTEL_EXPORTER_OTLP_METRICS_CERTIFICATE=' . self::CERT,
        );

        self::assertCount(1, $this->metrics);
    }

    #[Group('config-file'), Group('logs')]
    public function testConfigFileCaFileTrustsSelfSignedLogsCollector(): void {
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
                        tls:
                          ca_file: ${OTEL_EXPORTER_OTLP_LOGS_CERTIFICATE}
        YAML, static function (): void {
            Globals::loggerProvider()->getLogger('tls-test')
                ->logRecordBuilder()
                ->setBody('tls-config-log')
                ->emit();
        },
            'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=' . $this->tlsBaseUrl . '/v1/logs',
            'OTEL_EXPORTER_OTLP_LOGS_CERTIFICATE=' . self::CERT,
        );

        self::assertCount(1, $this->logs);
    }

    #[Group('config-file'), Group('traces')]
    public function testUnknownCaIsRejectedByDefaultVerification(): void {
        /*
         * Without any tls configuration the exporter keeps default peer
         * verification, so the self-signed collector is rejected and
         * nothing is exported. (export_timeout bounds the exporter's retry
         * backoff after the failed attempt.)
         */
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    export_timeout: 600
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('tls-test')
                ->spanBuilder('tls-untrusted')
                ->startSpan()
                ->end();
        },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->tlsBaseUrl . '/v1/traces',
        );

        self::assertSame([], $this->traces);
        self::assertStringContainsString(
            'certificate',
            strtolower($this->lastStderr),
        );
    }

    #[Group('env'), Group('traces')]
    public function testEnvClientCertificateIsPresentedAndVerified(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('tls-test')
                    ->spanBuilder('mtls-span')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->mtlsBaseUrl . '/v1/traces',
            'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=' . self::CERT,
            'OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE=' . self::CLIENT_CERT,
            'OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEY=' . self::CLIENT_KEY,
        );

        /*
         * The mTLS collector trusts only the dedicated client
         * certificate fixture: the span is exported only if
         * _CLIENT_CERTIFICATE/_CLIENT_KEY point to it (presenting the CA
         * file or no client certificate fails the handshake).
         */
        self::assertCount(1, $this->traces);
        self::assertSame(
            ['mtls-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('env'), Group('metrics')]
    public function testMetricsClientCertificateIsPresentedAndVerified(): void {
        /*
         * The per-signal client certificate variables for metrics: the
         * mTLS collector trusts only the dedicated client certificate
         * fixture.
         */
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()->getMeter('tls-test')
                    ->createCounter('mtls-metric')
                    ->add(1);
            },
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=' . $this->mtlsBaseUrl . '/v1/metrics',
            'OTEL_EXPORTER_OTLP_METRICS_CERTIFICATE=' . self::CERT,
            'OTEL_EXPORTER_OTLP_METRICS_CLIENT_CERTIFICATE=' . self::CLIENT_CERT,
            'OTEL_EXPORTER_OTLP_METRICS_CLIENT_KEY=' . self::CLIENT_KEY,
        );

        self::assertCount(1, $this->metrics);
    }

    #[Group('env'), Group('logs')]
    public function testLogsClientCertificateIsPresentedAndVerified(): void {
        /*
         * The per-signal client certificate variables for logs: the mTLS
         * collector trusts only the dedicated client certificate fixture.
         */
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()->getLogger('tls-test')
                    ->logRecordBuilder()
                    ->setBody('mtls-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=' . $this->mtlsBaseUrl . '/v1/logs',
            'OTEL_EXPORTER_OTLP_LOGS_CERTIFICATE=' . self::CERT,
            'OTEL_EXPORTER_OTLP_LOGS_CLIENT_CERTIFICATE=' . self::CLIENT_CERT,
            'OTEL_EXPORTER_OTLP_LOGS_CLIENT_KEY=' . self::CLIENT_KEY,
        );

        self::assertCount(1, $this->logs);
    }

    #[Group('config-file'), Group('traces')]
    public function testConfigFileClientCertificateIsPresentedAndVerified(): void {
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        tls:
                          ca_file: ${OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE}
                          cert_file: ${OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE}
                          key_file: ${OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEY}
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('tls-test')
                ->spanBuilder('mtls-config-span')
                ->startSpan()
                ->end();
        },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->mtlsBaseUrl . '/v1/traces',
            'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=' . self::CERT,
            'OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE=' . self::CLIENT_CERT,
            'OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEY=' . self::CLIENT_KEY,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['mtls-config-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('config-file'), Group('traces')]
    public function testMissingClientCertificateIsRejected(): void {
        /*
         * The collector requires a client certificate, but the client
         * presents none, so the connection is dropped during the request
         * and nothing is exported. PHP streams surface the peer
         * verification failure only after the handshake, so the client
         * sees a plain socket disconnect rather than a TLS error.
         * (export_timeout bounds the exporter's retry backoff after the
         * failed attempt.)
         */
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    export_timeout: 600
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        tls:
                          ca_file: ${OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE}
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('tls-test')
                ->spanBuilder('mtls-rejected')
                ->startSpan()
                ->end();
        },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->mtlsBaseUrl . '/v1/traces',
            'OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=' . self::CERT,
        );

        self::assertSame([], $this->traces);
        self::assertStringContainsString(
            'export failure',
            strtolower($this->lastStderr),
        );
    }

    #[Group('env'), Group('traces'), Group('metrics'), Group('logs')]
    public function testGenericCertificateTrustsSelfSignedCollector(): void {
        /*
         * The signal-agnostic certificate variable plus the signal-agnostic
         * endpoint: every signal trusts the self-signed collector.
         */
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('tls-test')
                    ->spanBuilder('generic-tls-span')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()->getMeter('tls-test')
                    ->createCounter('generic.tls-metric')
                    ->add(1);

                Globals::loggerProvider()->getLogger('tls-test')
                    ->logRecordBuilder()
                    ->setBody('generic-tls-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_ENDPOINT=' . $this->tlsBaseUrl,
            'OTEL_EXPORTER_OTLP_CERTIFICATE=' . self::CERT,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['generic-tls-span'],
            $this->spanNames($this->traces[0]),
        );
        self::assertCount(1, $this->metrics);
        self::assertCount(1, $this->logs);
    }

    #[Group('env'), Group('traces'), Group('metrics'), Group('logs')]
    public function testGenericClientCertificateIsPresentedAndVerified(): void {
        /*
         * The signal-agnostic client certificate variables: the mTLS
         * collector trusts only the dedicated client certificate fixture
         * for every signal.
         */
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('tls-test')
                    ->spanBuilder('generic-mtls-span')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()->getMeter('tls-test')
                    ->createCounter('generic.mtls-metric')
                    ->add(1);

                Globals::loggerProvider()->getLogger('tls-test')
                    ->logRecordBuilder()
                    ->setBody('generic-mtls-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_ENDPOINT=' . $this->mtlsBaseUrl,
            'OTEL_EXPORTER_OTLP_CERTIFICATE=' . self::CERT,
            'OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE=' . self::CLIENT_CERT,
            'OTEL_EXPORTER_OTLP_CLIENT_KEY=' . self::CLIENT_KEY,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['generic-mtls-span'],
            $this->spanNames($this->traces[0]),
        );
        self::assertCount(1, $this->metrics);
        self::assertCount(1, $this->logs);
    }
}

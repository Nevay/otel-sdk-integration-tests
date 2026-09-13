<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Http\Client\Response;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerTlsContext;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse;
use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Exports over HTTPS to a collector with a self-signed certificate. The
 * suite's regular capture server runs in plain HTTP, so this class exposes
 * a second, TLS-secured endpoint that captures into the same slots.
 */
final class TlsTest extends TestCase {
    use OTelEndpointTrait;

    private const CERT = __DIR__ . '/fixtures/tls/cert.pem';
    private const KEY  = __DIR__ . '/fixtures/tls/key.pem';

    private SocketHttpServer $tlsServer;
    private string $tlsBaseUrl = '';

    protected function setUp(): void {
        parent::setUp();

        $server = SocketHttpServer::createForDirectAccess(new NullLogger());
        $router = new Router($server, new NullLogger(), new DefaultErrorHandler());
        $router->addRoute('POST', 'v1/traces', new ClosureRequestHandler(function (Request $request): Response {
            return self::captureRequestBody(
                $request,
                $this->traces[],
                ExportTraceServiceRequest::class,
                ExportTraceServiceResponse::class,
            );
        }));

        $tlsContext = (new ServerTlsContext())
            ->withDefaultCertificate(new Certificate(self::CERT, self::KEY));

        $server->expose(
            new InternetAddress('127.0.0.1', 0),
            (new BindContext())->withTlsContext($tlsContext),
        );

        $server->start($router, new DefaultErrorHandler());
        $this->tlsBaseUrl = 'https://127.0.0.1:' . $server->getServers()[0]->getAddress()->getPort();
        $this->tlsServer = $server;
    }

    protected function tearDown(): void {
        $this->tlsServer->stop();

        parent::tearDown();
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

    #[Group('config-file'), Group('traces')]
    public function testConfigFileCaFileTrustsSelfSignedCollector(): void {
        $caFile = self::CERT;

        $this->runOTelConfig(<<<YAML
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: {$this->tlsBaseUrl}/v1/traces
                        tls:
                          ca_file: {$caFile}
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('tls-test')
                ->spanBuilder('tls-config-span')
                ->startSpan()
                ->end();
        });

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['tls-config-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('config-file'), Group('traces')]
    public function testUnknownCaIsRejectedByDefaultVerification(): void {
        /*
         * Without any tls configuration the exporter keeps default peer
         * verification, so the self-signed collector is rejected and
         * nothing is exported. (The shutdown timeout bounds the exporter's
         * retry backoff.)
         */
        $this->runOTelConfig(<<<YAML
            file_format: "1.2"

            distribution:
              tbachert/otel-sdk:
                shutdown_timeout: 1

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: {$this->tlsBaseUrl}/v1/traces
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('tls-test')
                ->spanBuilder('tls-untrusted')
                ->startSpan()
                ->end();
        });

        self::assertSame([], $this->traces);
        self::assertStringContainsString(
            'certificate',
            strtolower($this->lastStderr),
        );
    }
}

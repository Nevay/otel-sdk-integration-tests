<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('env')]
final class EnvSdkTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * SDK enablement
     * =========================================================================
     */

    #[Group('traces'), Group('metrics'), Group('logs')]
    public function testSdkDisabled(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('disabled')
                    ->startSpan();

                $span->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('disabled.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('disabled'));
            },
            'OTEL_SDK_DISABLED=true',
        );

        self::assertSame([], $this->traces);
        self::assertSame([], $this->metrics);
        self::assertSame([], $this->logs);
    }

    #[Group('traces')]
    public function testSdkEnabled(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('enabled')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SDK_DISABLED=false',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);
        self::assertSpanNames(['enabled']);
    }

    /*
     * =========================================================================
     * Per-signal exporters
     * =========================================================================
     */

    #[Group('traces')]
    public function testTracesExporterNoneDisablesOnlyTraceExport(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('traces-none')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('traces-none.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('traces-none'));
            },
            'OTEL_TRACES_EXPORTER=none',
        );

        self::assertSame([], $this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);
    }

    #[Group('metrics')]
    public function testMetricsExporterNoneDisablesOnlyMetricExport(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('metrics-none')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('metrics-none.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('metrics-none'));
            },
            'OTEL_METRICS_EXPORTER=none',
        );

        self::assertNotEmpty($this->traces);
        self::assertSame([], $this->metrics);
        self::assertNotEmpty($this->logs);
    }

    #[Group('logs')]
    public function testLogsExporterNoneDisablesOnlyLogExport(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('logs-none')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('logs-none.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('logs-none'));
            },
            'OTEL_LOGS_EXPORTER=none',
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertSame([], $this->logs);
    }

    /*
     * =========================================================================
     * SDK log level
     * =========================================================================
     */

    #[Group('traces')]
    public function testLogLevelControlsSdkLogging(): void {
        $failEndpoint = 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . str_replace(
            '/v1/traces',
            '/v1/fail',
            $this->env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'],
        );

        $emitSpan = static function (): void {
            Globals::tracerProvider()
                ->getTracer('test')
                ->spanBuilder('log-level')
                ->startSpan()
                ->end();
        };

        /*
         * The default level (info) reports the export failure as a warning.
         */
        $this->runOTel($emitSpan, $failEndpoint);

        self::assertStringContainsString(
            'Export failure',
            $this->lastStderr,
        );

        /*
         * At level error, the warning is suppressed...
         */
        $this->runOTel($emitSpan, $failEndpoint, 'OTEL_LOG_LEVEL=error');

        self::assertStringNotContainsString(
            'Export failure',
            $this->lastStderr,
        );

        /*
         * ...but error-level messages such as the initialization failure are
         * still logged.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "9.9"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            $emitSpan,
            'OTEL_LOG_LEVEL=error',
        );

        /*
         * The SDK capitalizes the product name differently in this code
         * path ('OpenTelemetry' vs 'opentelemetry'), so compare
         * case-insensitively.
         */
        self::assertStringContainsString(
            'error during opentelemetry initialization',
            strtolower($this->lastStderr),
        );
    }

    #[Group('traces')]
    public function testConsoleExporterWritesOtlpToStdout(): void
    {
        $output = $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('console-span')
                    ->startSpan()
                    ->end();

                echo "MARKER-STDOUT\n";
            },
            'OTEL_TRACES_EXPORTER=console',
        );

        /*
         * The console exporter appends newline-terminated OTLP/JSON
         * messages to the process' stdout.
         */
        $marker = "MARKER-STDOUT\n";

        self::assertStringStartsWith($marker, $output);

        $names = [];

        foreach (array_filter(explode("\n", substr($output, strlen($marker)))) as $json) {
            $request = new ExportTraceServiceRequest();
            $request->mergeFromJsonString($json, true);

            foreach ($request->getResourceSpans() as $resourceSpans) {
                foreach ($resourceSpans->getScopeSpans() as $scopeSpans) {
                    foreach ($scopeSpans->getSpans() as $span) {
                        $names[] = $span->getName();
                    }
                }
            }
        }

        self::assertContains('console-span', $names);

        /*
         * The span went to the console, not to the HTTP collector.
         */
        self::assertSame([], $this->traces);
    }
}

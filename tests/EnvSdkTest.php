<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
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
    /*
     * Vendor-specific: the specification defines OTEL_LOG_LEVEL but not the
     * log level of individual self-diagnostic messages. This test pins down
     * tbachert/otel-sdk's classification (export failures are logged at
     * warning level, initialization errors at error level), which other SDKs
     * may legitimately classify differently.
     */
    #[Group('tbachert')]
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
    public function testConsoleExporterWritesSpansToStdout(): void
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
         * The specification leaves the console exporter's output format
         * unspecified ("can vary between implementations"), so we only pin
         * down that the span reaches stdout and does not go to the OTLP
         * HTTP collector.
         */
        self::assertStringContainsString("MARKER-STDOUT\n", $output);
        self::assertStringContainsString('console-span', $output);

        /*
         * The span went to the console, not to the HTTP collector.
         */
        self::assertSame([], $this->traces);
    }

    /**
     * Boolean environment variables are only true for the case-insensitive
     * string "true": OTEL_SDK_DISABLED=1 must NOT disable the SDK, so the
     * span is exported.
     */
    public function testSdkDisabledDoesNotAcceptOneAsTrue(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SDK_DISABLED=1',
        );

        self::assertNotEmpty($this->traces);
    }

    /**
     * Boolean environment variables are case-insensitive: OTEL_SDK_DISABLED=TRUE
     * disables the SDK, so nothing is exported.
     */
    public function testSdkDisabledAcceptsCaseInsensitiveTrue(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SDK_DISABLED=TRUE',
        );

        self::assertEmpty($this->traces);
    }

    /**
     * OTEL_SDK_DISABLED disables the telemetry signals, but it has no effect
     * on propagators: a traceparent round-trip must still work.
     */
    public function testDisabledSdkDoesNotDisablePropagators(): void
    {
        $carrierJson = $this->runOTel(
            static function (): void {
                $incoming = [
                    'traceparent' => '00-11111111111111112222222222222222-3333333333333333-01',
                ];

                $context = Globals::propagator()->extract($incoming);

                $carrier = [];
                Globals::propagator()->inject($carrier, null, $context);

                echo json_encode($carrier);
            },
            'OTEL_SDK_DISABLED=true',
        );

        $carrier = json_decode($carrierJson, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('traceparent', $carrier);
        self::assertStringContainsString(
            '11111111111111112222222222222222',
            $carrier['traceparent'],
        );
    }

    /**
     * OTEL_LOG_LEVEL=none disables the SDK internal logger: an
     * initialization error (unrecognized OTLP protocol) must not be written
     * to stderr.
     *
     * open-telemetry/sdk logs the initialization error through its level-
     * aware logger, so this test proves that "none" suppresses it.
     * tbachert/otel-sdk handles an unrecognized protocol gracefully (warning
     * plus default transport), so no initialization error occurs and the
     * assertion holds trivially; note that its dedicated init-error logger
     * in the autoload bootstrap still ignores OTEL_LOG_LEVEL whenever an
     * initialization error does occur.
     */
    public function testLogLevelNoneSuppressesSelfDiagnostics(): void
    {
        $this->runOTel(
            static function (): void {
                // Any Globals access triggers SDK initialization, which
                // fails on the unrecognized protocol value.
                Globals::tracerProvider();
            },
            'OTEL_EXPORTER_OTLP_PROTOCOL=carrier-pigeon',
            'OTEL_LOG_LEVEL=none',
        );

        self::assertStringNotContainsString(
            'initialization',
            strtolower($this->lastStderr),
        );
    }
}

<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/*
 * =========================================================================
 * Official SDK specific behavior
 *
 * Environment variables that are not part of the official specification but
 * are implemented by open-telemetry/sdk, plus spec-defined behavior that only
 * applies to this SDK (such as the reserved telemetry.sdk.name). These tests
 * run in the official run only (group "official") and document the
 * vendor-specific surface of the official SDK.
 * =========================================================================
 */
#[Group('official')]
final class OfficialSpecificTest extends TestCase {
    use OTelEndpointTrait;

    #[Group('env'), Group('traces')]
    public function testPhpTracesProcessorNoneDisablesSpanExport(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('processor-none')
                    ->startSpan();

                $span->end();
            },
            'OTEL_PHP_TRACES_PROCESSOR=none',
        );

        /*
         * The no-op span processor never forwards spans to the exporter.
         */
        self::assertSame([], $this->traces);
    }

    #[Group('env'), Group('logs')]
    public function testPhpLogsProcessorNoneDisablesLogExport(): void {
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('processor-none'));
            },
            'OTEL_PHP_LOGS_PROCESSOR=none',
        );

        /*
         * The no-op log record processor never forwards records to the
         * exporter.
         */
        self::assertSame([], $this->logs);
    }

    #[Group('env'), Group('traces')]
    public function testPhpDetectorsEnvVarRestrictsResourceDetectors(): void {
        $emitSpan = static function (): void {
            $span = Globals::tracerProvider()
                ->getTracer('test')
                ->spanBuilder('detectors')
                ->startSpan();

            $span->end();
        };

        /*
         * By default the host and process detectors are active...
         */
        $this->runOTel($emitSpan);

        $keys = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].resource.attributes[*].key',
        );
        self::assertContains('host.id', $keys);
        self::assertContains('process.pid', $keys);

        /*
         * ...but restricting the detector list to "env" drops them while
         * the environment-derived service name remains.
         */
        $this->runOTel($emitSpan, 'OTEL_PHP_DETECTORS=env');

        $keys = $this->path(
            $this->traces[1],
            '$.resourceSpans[*].resource.attributes[*].key',
        );
        self::assertNotContains('host.id', $keys);
        self::assertNotContains('process.pid', $keys);
        self::assertContains('service.name', $keys);
    }

    #[Group('env'), Group('traces')]
    public function testPhpLogDestinationNoneSuppressesDiagnostics(): void {
        $noop = static function (): void {
            Globals::tracerProvider()->getTracer('test');
        };

        /*
         * Control: an invalid sampler value aborts SDK initialization and
         * the error is reported on stderr by default.
         */
        $this->runOTelExpectingInitError($noop, 'OTEL_TRACES_SAMPLER=bogus-value');

        /*
         * With the log destination set to "none" the same initialization
         * error is suppressed (runOTel asserts the absence of the init
         * error message on stderr).
         */
        $this->runOTel(
            $noop,
            'OTEL_TRACES_SAMPLER=bogus-value',
            'OTEL_PHP_LOG_DESTINATION=none',
        );
    }

    #[Group('env'), Group('traces'), Group('metrics')]
    public function testPhpInternalMetricsEnvVarExportsSdkMetrics(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('internal-metrics')
                    ->startSpan();

                $span->end();
            },
            'OTEL_PHP_INTERNAL_METRICS_ENABLED=true',
        );

        /*
         * The SDK records its own instrumentation (meter "io.opentelemetry.sdk")
         * and exports it with the configured metrics exporter.
         */
        self::assertNotEmpty($this->metrics);

        $names = [];
        foreach ($this->metrics as $payload) {
            $names = array_merge(
                $names,
                $this->path($payload, '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name'),
            );
        }

        self::assertContains('otel.sdk.span.started', $names);
    }

    #[Group('resource')]
    public function testDefaultResourceUsesReservedSdkName(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('default-resource-sdk-name')
                    ->startSpan()
                    ->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The reference implementation MUST identify itself with the reserved
         * name "opentelemetry"; the shared resource test (spec group) only
         * requires a non-empty identifier, which holds for every SDK.
         */
        self::assertSame(
            'opentelemetry',
            $this->resourceAttribute($this->traces[0], 'telemetry.sdk.name'),
        );
    }
}

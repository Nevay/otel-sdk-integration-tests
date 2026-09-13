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
}

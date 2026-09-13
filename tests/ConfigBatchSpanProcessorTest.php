<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use PHPUnit\Framework\TestCase;

final class ConfigBatchSpanProcessorTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Batch Span Processor
     * =========================================================================
     */

    public function testBatchSpanProcessorMaxExportBatchSize(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    schedule_delay: 60000
                    max_queue_size: 10
                    max_export_batch_size: 2
                    export_timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                for ($i = 1; $i <= 5; ++$i) {
                    $span = $tracer
                        ->spanBuilder("span-{$i}")
                        ->startSpan();

                    $span->end();
                }
            },
        );

        self::assertCount(3, $this->traces);

        self::assertCount(
            2,
            $this->spansInExport($this->traces[0]),
        );

        self::assertCount(
            2,
            $this->spansInExport($this->traces[1]),
        );

        self::assertCount(
            1,
            $this->spansInExport($this->traces[2]),
        );
    }

    public function testBatchSpanProcessorScheduleDelay(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    schedule_delay: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('scheduled')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        self::assertSame(
            ['scheduled'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testBatchSpanProcessorExportTimeoutIsAccepted(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    export_timeout: 100
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('timeout')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertIsArray($this->traces);
    }
}

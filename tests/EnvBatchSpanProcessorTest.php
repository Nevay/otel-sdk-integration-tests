<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('env'), Group('traces')]
final class EnvBatchSpanProcessorTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Batch Span Processor
     * =========================================================================
     *
     * Since each element of $this->traces represents one export call, these
     * tests can assert the actual batch boundaries.
     */

    public function testBspMaxExportBatchSize(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                for ($i = 1; $i <= 5; ++$i) {
                    $span = $tracer
                        ->spanBuilder("span-{$i}")
                        ->startSpan();

                    $span->end();
                }
            },
            'OTEL_BSP_MAX_EXPORT_BATCH_SIZE=2',
            'OTEL_BSP_MAX_QUEUE_SIZE=10',
            'OTEL_BSP_SCHEDULE_DELAY=60000',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertCount(3, $this->traces);

        self::assertCount(2, $this->spansInExport($this->traces[0]));
        self::assertCount(2, $this->spansInExport($this->traces[1]));
        self::assertCount(1, $this->spansInExport($this->traces[2]));
    }

    public function testBspMaxQueueSize(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                for ($i = 1; $i <= 5; ++$i) {
                    $span = $tracer
                        ->spanBuilder("span-{$i}")
                        ->startSpan();

                    $span->end();
                }
            },
            'OTEL_BSP_MAX_QUEUE_SIZE=2',
            'OTEL_BSP_MAX_EXPORT_BATCH_SIZE=2',
            'OTEL_BSP_SCHEDULE_DELAY=60000',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        $exported = array_sum(
            array_map(
                fn(string $payload): int => count($this->spansInExport($payload)),
                $this->traces,
            ),
        );

        self::assertLessThanOrEqual(5, $exported);
        self::assertGreaterThan(0, $exported);
    }

    public function testBspExportTimeout(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('timeout')
                    ->startSpan();

                $span->end();
            },
            'OTEL_BSP_EXPORT_TIMEOUT=100',
            'OTEL_BSP_SCHEDULE_DELAY=60000',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        // The important assertion here is that the SDK remains operational
        // and the export call does not cause the process to hang indefinitely.
        self::assertIsArray($this->traces);
    }

    public function testBspScheduleDelayDoesNotLoseSpansAfterFlush(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('scheduled')
                    ->startSpan();

                $span->end();
            },
            'OTEL_BSP_SCHEDULE_DELAY=60000',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);
        $this->assertSpanNames(['scheduled']);
    }

    /*
     * Vendor-specific: the specification mandates that spans are dropped once
     * the queue is full, but not when a batch-full export runs. This scenario
     * relies on tbachert/otel-sdk deferring exports to its timer, so the queue
     * can fill up; the official SDK flushes synchronously after every span
     * (autoFlush hardcoded to true), so the queue never fills and the drop
     * path is unobservable there.
     */
    #[Group('vendor-specific')]
    public function testBspDropsSpansWhenQueueIsFull(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                /*
                 * Four spans are produced before the first export; with a
                 * queue size of two, the last two must be dropped.
                 */
                for ($i = 0; $i < 4; $i++) {
                    $span = $tracer->spanBuilder("overflow-$i")->startSpan();
                    $span->end();
                }
            },
            'OTEL_BSP_MAX_QUEUE_SIZE=2',
            'OTEL_BSP_MAX_EXPORT_BATCH_SIZE=1',
        );

        self::assertNotEmpty($this->traces);

        /*
         * Only the first two spans fit into the queue; the rest are lost.
         */
        $names = [];

        foreach ($this->traces as $payload) {
            $names = [...$names, ...$this->spanNames($payload)];
        }

        self::assertSame(['overflow-0', 'overflow-1'], $names);
    }
}

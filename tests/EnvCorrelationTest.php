<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

    #[Group('env')]
final class EnvCorrelationTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Cross-signal correlation
     * =========================================================================
     */

    #[Group('traces'), Group('logs')]
    public function testLogRecordEmittedInSpanCarriesTraceContext(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('log-parent')
                    ->startSpan();

                $scope = $span->activate();

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('inside-span')
                    ->emit();

                $scope->detach();
                $span->end();
            },
        );

        $spans = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "log-parent")]',
        );

        self::assertCount(1, $spans);

        $logRecords = $this->logsInExport($this->logs[0]);

        self::assertCount(1, $logRecords);

        /*
         * The log record is correlated with the active span. (OTLP JSON
         * encodes byte fields as base64, while this SDK's env-mode span
         * payloads use hex; see tbachert-sdk-issues.md.)
         */
        self::assertSame(
            $spans[0]['traceId'],
            bin2hex(base64_decode($logRecords[0]['traceId'])),
        );

        self::assertSame(
            $spans[0]['spanId'],
            bin2hex(base64_decode($logRecords[0]['spanId'])),
        );
    }

    #[Group('traces'), Group('logs')]
    public function testLogRecordInNestedSpanReferencesInnerSpan(): void {
        $this->runOTel(
            static function (): void {
                $outer = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('nested-outer')
                    ->startSpan();

                $outerScope = $outer->activate();

                $inner = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('nested-inner')
                    ->startSpan();

                $innerScope = $inner->activate();

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('inside-nested-span')
                    ->emit();

                $innerScope->detach();
                $inner->end();

                $outerScope->detach();
                $outer->end();
            },
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->logs);

        $spans = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[*]',
        );

        $byName = array_column($spans, null, 'name');

        self::assertArrayHasKey('nested-outer', $byName);
        self::assertArrayHasKey('nested-inner', $byName);

        /*
         * The inner span is a child of the outer span...
         */
        self::assertSame(
            $byName['nested-outer']['spanId'],
            $byName['nested-inner']['parentSpanId'],
        );

        /*
         * ...and the log record references the inner (active) span,
         * not the outer one.
         */
        $logRecords = $this->logsInExport($this->logs[0]);

        self::assertCount(1, $logRecords);

        self::assertSame(
            $byName['nested-inner']['spanId'],
            bin2hex(base64_decode($logRecords[0]['spanId'])),
        );

        self::assertSame(
            $byName['nested-outer']['traceId'],
            bin2hex(base64_decode($logRecords[0]['traceId'])),
        );
    }

    #[Group('traces'), Group('metrics')]
    public function testMetricsExemplarCarriesActiveSpanContext(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('exemplar-parent')
                    ->startSpan();

                $scope = $span->activate();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('exemplar.counter')
                    ->add(1);

                $scope->detach();
                $span->end();
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=always_on',
        );

        $spans = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "exemplar-parent")]',
        );

        self::assertCount(1, $spans);

        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "exemplar.counter")]..exemplars[*]',
        );

        self::assertCount(1, $exemplars);

        self::assertSame('1', $exemplars[0]['asInt']);

        /*
         * The exemplar references the active span at measurement time.
         */
        self::assertSame(
            $spans[0]['traceId'],
            bin2hex(base64_decode($exemplars[0]['traceId'])),
        );

        self::assertSame(
            $spans[0]['spanId'],
            bin2hex(base64_decode($exemplars[0]['spanId'])),
        );
    }

    #[Group('traces'), Group('metrics'), Group('logs')]
    public function testAllSignalsCorrelateWithinOneTrace(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('kitchen-sink')
                    ->startSpan();

                $scope = $span->activate();

                /*
                 * A measurement and a log record inside the same active
                 * span.
                 */
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('sink.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('inside-sink-span')
                    ->emit();

                $scope->detach();
                $span->end();
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=always_on',
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);

        $spans = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "kitchen-sink")]',
        );

        self::assertCount(1, $spans);

        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "sink.counter")]..exemplars[*]',
        );

        $logRecords = $this->logsInExport($this->logs[0]);

        self::assertCount(1, $exemplars);
        self::assertCount(1, $logRecords);

        /*
         * All three signals reference the same span context. (Exemplars
         * and log records encode ids as base64; spans use hex.)
         */
        self::assertSame(
            bin2hex(base64_decode($exemplars[0]['traceId'])),
            $spans[0]['traceId'],
        );

        self::assertSame(
            bin2hex(base64_decode($exemplars[0]['spanId'])),
            $spans[0]['spanId'],
        );

        self::assertSame(
            $exemplars[0]['traceId'],
            $logRecords[0]['traceId'],
        );

        self::assertSame(
            $exemplars[0]['spanId'],
            $logRecords[0]['spanId'],
        );
    }
}

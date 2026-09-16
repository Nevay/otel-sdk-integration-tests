<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('config-file'), Group('traces')]
final class ConfigLimitsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * General attribute limits
     * =========================================================================
     */

    public function testGeneralAttributeLimits(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            attribute_limits:
              attribute_count_limit: 2
              attribute_value_length_limit: 4

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('attribute-limits')
                    ->setAttribute('a1', '123456789')
                    ->setAttribute('a2', '123456789')
                    ->setAttribute('a3', '123456789')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertCount(
            2,
            $this->spanAttributes('attribute-limits'),
        );

        self::assertSame(
            '1234',
            $this->spanAttribute(
                'attribute-limits',
                'a1',
            ),
        );
    }

    public function testAttributeValueDepthLimitTruncatesNestedValues(): void
    {
        /*
         * With a depth limit of one, arrays inside attribute values are
         * replaced by empty arrays; scalar entries of the same array are
         * kept.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            attribute_limits:
              attribute_value_depth_limit: 1

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('depth-limit')
                    ->setAttribute('nested', ['a' => ['b' => 'c'], 's' => 'keep'])
                    ->startSpan()
                    ->end();
            },
        );

        $base = '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "depth-limit")].attributes[?(@.key == "nested")].value.kvlistValue.values';

        self::assertSame(
            ['a', 's'],
            $this->path($this->combinedTracePayload(), $base . '[*].key'),
        );

        /*
         * The nested array is clipped to an empty array value (the entry
         * itself survives, its contents do not).
         */
        self::assertSame(
            [['key' => 'a', 'value' => ['arrayValue' => []]]],
            $this->path($this->combinedTracePayload(), $base . '[0]'),
        );

        self::assertSame(
            ['keep'],
            $this->path($this->combinedTracePayload(), $base . '[1].value.stringValue'),
        );
    }

    public function testTracerProviderAttributeValueDepthLimitTruncatesNestedValues(): void {
        /*
         * The per-signal limit applies to spans, independently of the global
         * attribute limits.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              limits:
                attribute_value_depth_limit: 1

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('tracer-depth-limit')
                    ->setAttribute('nested', ['a' => ['b' => 'c'], 's' => 'keep'])
                    ->startSpan()
                    ->end();
            },
        );

        $base = '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "tracer-depth-limit")].attributes[?(@.key == "nested")].value.kvlistValue.values';

        self::assertSame(
            ['a', 's'],
            $this->path($this->combinedTracePayload(), $base . '[*].key'),
        );

        /*
         * The nested array is clipped to an empty array value (the entry
         * itself survives, its contents do not).
         */
        self::assertSame(
            [['key' => 'a', 'value' => ['arrayValue' => []]]],
            $this->path($this->combinedTracePayload(), $base . '[0]'),
        );

        self::assertSame(
            ['keep'],
            $this->path($this->combinedTracePayload(), $base . '[1].value.stringValue'),
        );
    }

    /*
     * =========================================================================
     * Span limits
     * =========================================================================
     */

    public function testSpanLimits(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              limits:
                attribute_count_limit: 2
                attribute_value_length_limit: 4
                event_count_limit: 2
                link_count_limit: 2
                event_attribute_count_limit: 2
                link_attribute_count_limit: 2

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                $source = $tracer
                    ->spanBuilder('source')
                    ->startSpan();

                $sourceContext = $source->getContext();

                $source->end();

                $span = $tracer
                    ->spanBuilder('limited')
                    ->setAttribute('a1', '123456789')
                    ->setAttribute('a2', '123456789')
                    ->setAttribute('a3', '123456789')
                    ->addLink(
                        $sourceContext,
                        [
                            'a1' => '1',
                            'a2' => '2',
                            'a3' => '3',
                        ],
                    )
                    ->addLink($sourceContext)
                    ->addLink($sourceContext)
                    ->startSpan();

                $span
                    ->addEvent(
                        'event-1',
                        [
                            'a1' => '1',
                            'a2' => '2',
                            'a3' => '3',
                        ],
                    )
                    ->addEvent('event-2')
                    ->addEvent('event-3');

                $span->end();
            },
        );

        self::assertCount(
            2,
            $this->spanAttributes('limited'),
        );

        self::assertSame(
            '1234',
            $this->spanAttribute('limited', 'a1'),
        );

        self::assertCount(
            2,
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "limited")].events[*]',
            ),
        );

        self::assertCount(
            2,
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "limited")].links[*]',
            ),
        );

        self::assertCount(
            2,
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "limited")].events[0].attributes[*]',
            ),
        );

        self::assertCount(
            2,
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "limited")].links[0].attributes[*]',
            ),
        );
    }
}

<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
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

    public function testDefaultAttributeCountLimitIs128(): void
    {
        /*
         * Without any attribute limits configured, the spec default applies:
         * a span keeps at most 128 attributes.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

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
                    ->spanBuilder('default-count-limit');

                for ($i = 0; $i < 130; $i++) {
                    $span->setAttribute(sprintf('attr-%03d', $i), 'v' . $i);
                }

                $span->startSpan()->end();
            },
        );

        self::assertCount(
            128,
            $this->spanAttributes('default-count-limit'),
        );

        self::assertSame(
            'v0',
            $this->spanAttribute('default-count-limit', 'attr-000'),
        );
    }

    public function testDefaultAttributeValueDepthLimitIs64(): void
    {
        /*
         * Without any attribute limits configured, the spec default applies:
         * nested array values are truncated at a depth of 64.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $value = 'leaf';

                /* A chain of 69 nested arrays; the leaf sits at depth 70. */
                for ($i = 0; $i < 69; $i++) {
                    $value = [$value];
                }

                Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('default-depth-limit')
                    ->setAttribute('deep', $value)
                    ->startSpan()
                    ->end();
            },
        );

        $values = $this->path(
            $this->combinedTracePayload(),
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "default-depth-limit")].attributes[?(@.key == "deep")].value.arrayValue',
        );

        self::assertCount(1, $values);

        /*
         * Walk the nested arrays: levels 1 through 64 are intact, and the
         * value at level 65 has been replaced by an empty array.
         */
        $node = $values[0];
        $levels = 0;

        while (is_array($node) && !empty($node['values'])) {
            $levels++;
            /* Elements of `values` are AnyValue objects: the nested
             * ArrayValue sits directly under `arrayValue`. */
            $inner = $node['values'][0] ?? [];
            $node = is_array($inner) ? ($inner['arrayValue'] ?? []) : [];
        }

        self::assertSame(64, $levels);
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

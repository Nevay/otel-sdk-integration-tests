<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('env'), Group('traces')]
final class EnvSpanLimitsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Attribute limits
     * =========================================================================
     */

    public function testSpanAttributeValueLengthLimitOverridesGlobalLimit(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('environment-test')
                    ->spanBuilder('environment.span.attribute.value.length')
                    ->startSpan();

                $span->setAttribute(
                    'test.attribute',
                    '1234567890',
                );

                $span->end();
            },
            'OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT=3',
            'OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT=5',
        );

        /*
         * Per the specification, the signal-specific limit takes precedence
         * over the global one: truncated at 5, not 3.
         */
        $value = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "environment.span.attribute.value.length")].attributes[?(@.key == "test.attribute")].value.stringValue',
        );

        self::assertSame(['12345'], $value);
    }

    public function testAttributeCountLimit(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('attribute-count')
                    ->setAttribute('a1', '1')
                    ->setAttribute('a2', '2')
                    ->setAttribute('a3', '3')
                    ->setAttribute('a4', '4')
                    ->startSpan();

                $span->end();
            },
            'OTEL_ATTRIBUTE_COUNT_LIMIT=2',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertCount(
            2,
            $this->spanAttributes('attribute-count'),
        );
    }

    public function testAttributeValueLengthLimit(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('attribute-length')
                    ->setAttribute('test.attribute', 'abcdefghij')
                    ->startSpan();

                $span->end();
            },
            'OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT=4',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertSame(
            'abcd',
            $this->spanAttribute(
                'attribute-length',
                'test.attribute',
            ),
        );
    }

    /*
     * =========================================================================
     * Span limits
     * =========================================================================
     */

    public function testSpanAttributeCountLimit(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('span-attribute-count')
                    ->setAttribute('a1', '1')
                    ->setAttribute('a2', '2')
                    ->setAttribute('a3', '3')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SPAN_ATTRIBUTE_COUNT_LIMIT=2',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertCount(
            2,
            $this->spanAttributes('span-attribute-count'),
        );
    }

    public function testSpanEventCountLimit(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('event-count')
                    ->startSpan();

                $span->addEvent('event-1');
                $span->addEvent('event-2');
                $span->addEvent('event-3');

                $span->end();
            },
            'OTEL_SPAN_EVENT_COUNT_LIMIT=2',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertCount(
            2,
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "event-count")].events[*]',
            ),
        );
    }

    public function testSpanLinkCountLimit(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $source = $tracer
                    ->spanBuilder('source')
                    ->startSpan();

                $context = $source->getContext();
                $source->end();

                $builder = $tracer->spanBuilder('link-count');

                $builder->addLink($context);
                $builder->addLink($context);
                $builder->addLink($context);

                $span = $builder->startSpan();
                $span->end();
            },
            'OTEL_SPAN_LINK_COUNT_LIMIT=2',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertCount(
            2,
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "link-count")].links[*]',
            ),
        );
    }

    public function testEventAttributeCountLimit(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('event-attributes')
                    ->startSpan();

                $span->addEvent(
                    'event',
                    [
                        'a1' => '1',
                        'a2' => '2',
                        'a3' => '3',
                    ],
                );

                $span->end();
            },
            'OTEL_EVENT_ATTRIBUTE_COUNT_LIMIT=2',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        /*
         * Only the first two attributes (in insertion order) survive; the
         * rest are dropped.
         */
        $keys = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "event-attributes")].events[0].attributes[*].key',
        );

        self::assertSame(['a1', 'a2'], array_values($keys));
    }

    public function testLinkAttributeCountLimit(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $source = $tracer
                    ->spanBuilder('source')
                    ->startSpan();

                $context = $source->getContext();
                $source->end();

                $span = $tracer
                    ->spanBuilder('link-attributes')
                    ->addLink(
                        $context,
                        [
                            'a1' => '1',
                            'a2' => '2',
                            'a3' => '3',
                        ],
                    )
                    ->startSpan();

                $span->end();
            },
            'OTEL_LINK_ATTRIBUTE_COUNT_LIMIT=2',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertCount(
            2,
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "link-attributes")].links[0].attributes[*]',
            ),
        );
    }

    /**
     * Numeric environment variables: an unparseable value must be treated as
     * unset, so the default span attribute count limit (128) applies and all
     * three attributes are exported.
     * 
     * open-telemetry/sdk currently fails initialization instead of ignoring
     * the value.
     */
    public function testUnparseableAttributeCountLimitFallsBackToDefault(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->setAttribute('a', '1')
                    ->setAttribute('b', '2')
                    ->setAttribute('c', '3')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SPAN_ATTRIBUTE_COUNT_LIMIT=abc',
        );

        self::assertCount(3, $this->spanAttributes('probe'));
    }

    /**
     * A limit of zero means "no items allowed": with
     * OTEL_SPAN_LINK_COUNT_LIMIT=0 no links may be exported.
     */
    public function testZeroLinkCountLimitDropsAllLinks(): void
    {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('probe');

                $source = $tracer->spanBuilder('source')->startSpan();
                $context = $source->getContext();
                $source->end();

                $builder = $tracer->spanBuilder('zero-links');
                $builder->addLink($context);
                $builder->addLink($context);

                $builder->startSpan()->end();
            },
            'OTEL_SPAN_LINK_COUNT_LIMIT=0',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertCount(
            0,
            $this->path(
                $this->combinedTracePayload(),
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "zero-links")].links[*]',
            ),
        );
    }

    /**
     * A limit of zero means "no items allowed": with
     * OTEL_SPAN_EVENT_COUNT_LIMIT=0 no events may be exported.
     */
    public function testZeroEventCountLimitDropsAllEvents(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('zero-events')
                    ->startSpan();

                $span->addEvent('event-1');
                $span->addEvent('event-2');

                $span->end();
            },
            'OTEL_SPAN_EVENT_COUNT_LIMIT=0',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertCount(
            0,
            $this->path(
                $this->combinedTracePayload(),
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "zero-events")].events[*]',
            ),
        );
    }
}

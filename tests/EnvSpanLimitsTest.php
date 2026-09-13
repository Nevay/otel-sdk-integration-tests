<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use PHPUnit\Framework\TestCase;

final class EnvSpanLimitsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Attribute limits
     * =========================================================================
     */

    public function testAttributeValueLengthLimitEnvironmentVariable(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('environment-test')
                    ->spanBuilder('environment.attribute.value.length')
                    ->startSpan();

                $span->setAttribute(
                    'test.attribute',
                    '1234567890',
                );

                $span->end();
            },
            'OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT=5',
        );

        $value = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "environment.attribute.value.length")].attributes[?(@.key == "test.attribute")].value.stringValue',
        );

        self::assertSame(['12345'], $value);
    }

    public function testAttributeCountLimitEnvironmentVariable(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('environment-test')
                    ->spanBuilder('environment.attribute.count')
                    ->startSpan();

                $span->setAttributes([
                    'test.attribute.1' => 'one',
                    'test.attribute.2' => 'two',
                    'test.attribute.3' => 'three',
                    'test.attribute.4' => 'four',
                ]);

                $span->end();
            },
            'OTEL_ATTRIBUTE_COUNT_LIMIT=2',
        );

        $attributes = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "environment.attribute.count")].attributes[*]',
        );

        self::assertCount(2, $attributes);
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

    public function testSpanEventCountLimitEnvironmentVariable(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('environment-test')
                    ->spanBuilder('environment.event.count')
                    ->startSpan();

                $span->addEvent('event.one');
                $span->addEvent('event.two');
                $span->addEvent('event.three');

                $span->end();
            },
            'OTEL_SPAN_EVENT_COUNT_LIMIT=1',
        );

        $events = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "environment.event.count")].events[*]',
        );

        self::assertCount(1, $events);
    }

    public function testSpanLinkCountLimitEnvironmentVariable(): void
    {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()
                    ->getTracer('environment-test');

                $spanContextOne = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $spanContextTwo = SpanContext::create(
                    'fedcba9876543210fedcba9876543210',
                    'fedcba9876543210',
                    TraceFlags::SAMPLED,
                );

                $span = $tracer
                    ->spanBuilder('environment.link.count')
                    ->addLink($spanContextOne)
                    ->addLink($spanContextTwo)
                    ->startSpan();

                $span->end();
            },
            'OTEL_SPAN_LINK_COUNT_LIMIT=1',
        );

        $links = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "environment.link.count")].links[*]',
        );

        self::assertCount(1, $links);
    }

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

        self::assertCount(
            2,
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "event-attributes")].events[0].attributes[*]',
            ),
        );
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
}

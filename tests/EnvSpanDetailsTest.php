<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('env'), Group('traces')]
final class EnvSpanDetailsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Span details
     * =========================================================================
     *
     * Export fidelity of span status, events and kinds.
     */

    public function testSpanStatusIsExported(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $error = $tracer->spanBuilder('status-error')->startSpan();
                $error->setStatus(StatusCode::STATUS_ERROR, 'boom');
                $error->end();

                $ok = $tracer->spanBuilder('status-ok')->startSpan();
                $ok->setStatus(StatusCode::STATUS_OK);
                $ok->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * OTLP status codes: 0 = unset, 1 = ok, 2 = error.
         */
        $spans = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[*]',
        );

        $statuses = array_column($spans, 'status', 'name');

        self::assertSame(['message' => 'boom', 'code' => 2], $statuses['status-error']);
        self::assertSame(['code' => 1], $statuses['status-ok']);
    }

    public function testSpanEventsAreExportedWithAttributes(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('events-span')
                    ->startSpan();

                $span->addEvent('first-event', ['event.attr' => 'ev']);
                $span->addEvent('second-event');

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        $events = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "events-span")].events',
        );

        self::assertCount(2, $events[0]);

        self::assertSame('first-event', $events[0][0]['name']);
        self::assertSame(
            [
                ['key' => 'event.attr', 'value' => ['stringValue' => 'ev']],
            ],
            $events[0][0]['attributes'],
        );

        /*
         * Events without attributes are exported without an attributes key.
         */
        self::assertSame('second-event', $events[0][1]['name']);
        self::assertArrayNotHasKey('attributes', $events[0][1]);
    }

    public function testSpanKindsAreExported(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                foreach (
                    [
                        'kind-internal' => SpanKind::KIND_INTERNAL,
                        'kind-server' => SpanKind::KIND_SERVER,
                        'kind-client' => SpanKind::KIND_CLIENT,
                        'kind-producer' => SpanKind::KIND_PRODUCER,
                        'kind-consumer' => SpanKind::KIND_CONSUMER,
                    ] as $name => $kind
                ) {
                    $span = $tracer->spanBuilder($name)->setSpanKind($kind)->startSpan();
                    $span->end();
                }
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * OTLP span kinds: 1 = internal, 2 = server, 3 = client,
         * 4 = producer, 5 = consumer.
         */
        $spans = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[*]',
        );

        $kinds = array_column($spans, 'kind', 'name');

        self::assertSame(1, $kinds['kind-internal']);
        self::assertSame(2, $kinds['kind-server']);
        self::assertSame(3, $kinds['kind-client']);
        self::assertSame(4, $kinds['kind-producer']);
        self::assertSame(5, $kinds['kind-consumer']);
    }

    public function testSpanAttributesPreserveValueTypes(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('typed-attributes')
                    ->startSpan();

                $span->setAttribute('string.value', 'text');
                $span->setAttribute('int.value', 42);
                $span->setAttribute('bool.value', true);
                $span->setAttribute('float.value', 0.5);
                $span->setAttribute('string.list.value', ['a', 'b']);

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        $attributes = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "typed-attributes")].attributes[*]',
        );

        $byKey = array_column($attributes, 'value', 'key');

        self::assertSame(['stringValue' => 'text'], $byKey['string.value']);

        /*
         * OTLP JSON encodes int64 values as strings.
         */
        self::assertSame(['intValue' => '42'], $byKey['int.value']);

        self::assertSame(['boolValue' => true], $byKey['bool.value']);
        self::assertSame(['doubleValue' => 0.5], $byKey['float.value']);

        /*
         * String arrays are exported as array values...
         */
        self::assertSame(
            [
                'arrayValue' => [
                    'values' => [
                        ['stringValue' => 'a'],
                        ['stringValue' => 'b'],
                    ],
                ],
            ],
            $byKey['string.list.value'],
        );
    }

    public function testSpanLinksAreExportedWithAttributes(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('linked-span')
                    ->addLink(SpanContext::create(
                        '11111111111111111111111111111111',
                        '1111111111111111',
                        TraceFlags::SAMPLED,
                    ), ['link.attr' => 'one'])
                    ->addLink(SpanContext::create(
                        '22222222222222222222222222222222',
                        '2222222222222222',
                        TraceFlags::SAMPLED,
                    ))
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        $links = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "linked-span")].links',
        );

        self::assertCount(1, $links);
        $links = $links[0];
        self::assertCount(2, $links);

        /*
         * Links are exported in the order they were added, with the
         * referenced trace/span ids and their attributes.
         */
        self::assertSame('11111111111111111111111111111111', $links[0]['traceId']);
        self::assertSame('1111111111111111', $links[0]['spanId']);
        self::assertSame(
            [['key' => 'link.attr', 'value' => ['stringValue' => 'one']]],
            $links[0]['attributes'],
        );

        /*
         * A link without attributes omits the attributes key entirely.
         */
        self::assertSame('22222222222222222222222222222222', $links[1]['traceId']);
        self::assertSame('2222222222222222', $links[1]['spanId']);
        self::assertArrayNotHasKey('attributes', $links[1]);
    }
}

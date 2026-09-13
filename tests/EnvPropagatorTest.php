<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\TestCase;

final class EnvPropagatorTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Propagators
     * =========================================================================
     */

    public function testPropagatorsEnvironmentVariableConfiguresTraceContextAndBaggage(): void
    {
        $output = $this->runOTel(
            static function (): void {
                $traceId = '0123456789abcdef0123456789abcdef';
                $spanId = '0123456789abcdef';

                $carrier = [
                    'traceparent' => sprintf(
                        '00-%s-%s-01',
                        $traceId,
                        $spanId,
                    ),
                    'baggage' => 'test-key=test-value',
                ];

                $context = Globals::propagator()->extract($carrier);
                $spanContext = Span::fromContext($context)->getContext();

                echo json_encode([
                    'traceId' => $spanContext->getTraceId(),
                    'spanId' => $spanContext->getSpanId(),
                    'sampled' => $spanContext->isSampled(),
                    'baggage' => Baggage::fromContext($context)->getValue('test-key'),
                ], JSON_THROW_ON_ERROR);
            },
            'OTEL_PROPAGATORS=tracecontext,baggage',
        );

        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            '0123456789abcdef0123456789abcdef',
            $result['traceId'],
        );

        self::assertSame(
            '0123456789abcdef',
            $result['spanId'],
        );

        self::assertTrue($result['sampled']);

        self::assertSame(
            'test-value',
            $result['baggage'],
        );
    }

    public function testPropagatorsEnvironmentVariableCanConfigureOnlyTraceContextPropagator(): void
    {
        $output = $this->runOTel(
            static function (): void {
                $carrier = [
                    'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
                    'baggage' => 'test-key=test-value',
                ];

                $context = Globals::propagator()->extract($carrier);
                $spanContext = Span::fromContext($context)->getContext();

                echo json_encode([
                    'valid' => $spanContext->isValid(),
                    'traceId' => $spanContext->getTraceId(),
                    'baggage' => Baggage::fromContext($context)->getValue('test-key'),
                ], JSON_THROW_ON_ERROR);
            },
            'OTEL_PROPAGATORS=tracecontext',
        );

        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($result['valid']);

        self::assertSame(
            '0123456789abcdef0123456789abcdef',
            $result['traceId'],
        );

        self::assertNull($result['baggage']);
    }

    public function testPropagatorsEnvironmentVariableAreUsedForInjection(): void
    {
        $output = $this->runOTel(
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $baggage = Baggage::fromContext($context)
                    ->toBuilder()
                    ->set('test-key', 'test-value')
                    ->build();

                $context = $baggage->storeInContext($context);

                $carrier = [];

                Globals::propagator()->inject(
                    $carrier,
                    null,
                    $context,
                );

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
            'OTEL_PROPAGATORS=tracecontext,baggage',
        );

        $carrier = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            $carrier['traceparent'],
        );

        self::assertSame(
            'test-key=test-value',
            $carrier['baggage'],
        );
    }

    public function testTraceContextPropagator(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $parent = $tracer
                    ->spanBuilder('parent')
                    ->startSpan();

                $scope = $parent->activate();

                $carrier = [];
                Globals::propagator()->inject($carrier);

                $scope->detach();
                $parent->end();

                $context = Globals::propagator()->extract($carrier);
                $scope = $context->activate();

                $child = $tracer
                    ->spanBuilder('child')
                    ->startSpan();

                $child->end();
                $scope->detach();
            },
            'OTEL_PROPAGATORS=tracecontext',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertSame(
            $this->spanIdByName('parent'),
            $this->spanParentIdByName('child'),
        );
    }

    public function testBaggagePropagator(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $span = $tracer
                    ->spanBuilder('baggage')
                    ->startSpan();

                $scope = $span->activate();

                $carrier = [];
                Globals::propagator()->inject($carrier);

                Globals::propagator()->extract($carrier);

                $scope->detach();
                $span->end();
            },
            'OTEL_PROPAGATORS=baggage',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);
    }

    public function testMultiplePropagators(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('multiple-propagators')
                    ->startSpan();

                $span->end();
            },
            'OTEL_PROPAGATORS=tracecontext,baggage',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);
    }

    public function testNonePropagator(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $parent = $tracer
                    ->spanBuilder('parent')
                    ->startSpan();

                $scope = $parent->activate();

                $carrier = [];
                Globals::propagator()->inject($carrier);

                $scope->detach();
                $parent->end();

                $context = Globals::propagator()->extract($carrier);
                $scope = $context->activate();

                $child = $tracer
                    ->spanBuilder('child')
                    ->startSpan();

                $child->end();
                $scope->detach();
            },
            'OTEL_PROPAGATORS=none',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertEmpty(
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "child")].parentSpanId',
            ),
        );
    }

    public function testDefaultPropagatorInjectsTraceParent(): void {
        /*
         * Without OTEL_PROPAGATORS, the default propagator still injects a
         * traceparent header. (In contrast, config-file mode without an
         * explicit propagator section does not inject anything.)
         */
        $output = $this->runOTel(
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $carrier = [];
                Globals::propagator()->inject($carrier, null, $context);

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
        );

        self::assertSame(
            [
                'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            ],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testB3PropagatorInjectsSingleHeader(): void {
        $output = $this->runOTel(
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $carrier = [];
                Globals::propagator()->inject($carrier, null, $context);

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
            'OTEL_PROPAGATORS=b3',
        );

        /*
         * The B3 single header is {traceId}-{spanId}-{samplingState}.
         */
        self::assertSame(
            [
                'b3' => '0123456789abcdef0123456789abcdef-0123456789abcdef-1',
            ],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testB3MultiPropagatorInjectsHeaders(): void {
        $output = $this->runOTel(
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $carrier = [];
                Globals::propagator()->inject($carrier, null, $context);

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
            'OTEL_PROPAGATORS=b3multi',
        );

        self::assertSame(
            [
                'X-B3-TraceId' => '0123456789abcdef0123456789abcdef',
                'X-B3-SpanId' => '0123456789abcdef',
                'X-B3-Sampled' => '1',
            ],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testB3PropagatorExtractsParentContext(): void {
        $this->runOTel(
            static function (): void {
                $carrier = [
                    'b3' => '4193e569320548f7b71d4c5a750d504c-6e0c63258deeeff4-1',
                ];

                $context = Globals::propagator()->extract($carrier);
                $scope = $context->activate();

                $child = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('b3-child')
                    ->startSpan();
                $child->end();

                $scope->detach();
            },
            'OTEL_PROPAGATORS=b3',
        );

        self::assertNotEmpty($this->traces);

        /*
         * The child span continues the extracted remote trace.
         */
        self::assertSame(
            ['6e0c63258deeeff4'],
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "b3-child")].parentSpanId',
            ),
        );

        self::assertSame(
            ['4193e569320548f7b71d4c5a750d504c'],
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "b3-child")].traceId',
            ),
        );
    }

    public function testB3MultiPropagatorExtractsParentContext(): void {
        $this->runOTel(
            static function (): void {
                $carrier = [
                    'X-B3-TraceId' => '4193e569320548f7b71d4c5a750d504c',
                    'X-B3-SpanId' => '6e0c63258deeeff4',
                    'X-B3-Sampled' => '1',
                ];

                $context = Globals::propagator()->extract($carrier);
                $scope = $context->activate();

                $child = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('b3multi-child')
                    ->startSpan();
                $child->end();

                $scope->detach();
            },
            'OTEL_PROPAGATORS=b3multi',
        );

        self::assertNotEmpty($this->traces);

        self::assertSame(
            ['6e0c63258deeeff4'],
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "b3multi-child")].parentSpanId',
            ),
        );
    }

    public function testB3NotSampledRemoteParentIsDropped(): void {
        $this->runOTel(
            static function (): void {
                /*
                 * Sampling state 0 in the B3 header: a parent-based sampler
                 * must not sample the resulting child span.
                 */
                $carrier = [
                    'b3' => '4193e569320548f7b71d4c5a750d504c-6e0c63258deeeff4-0',
                ];

                $context = Globals::propagator()->extract($carrier);
                $scope = $context->activate();

                $child = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('b3-dropped-child')
                    ->startSpan();
                $child->end();

                $scope->detach();
            },
            'OTEL_PROPAGATORS=b3',
            'OTEL_TRACES_SAMPLER=parentbased_always_on',
        );

        self::assertSame([], $this->traces);
    }
}

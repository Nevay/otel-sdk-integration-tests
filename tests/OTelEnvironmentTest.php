<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\TestCase;

final class OTelEnvironmentTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * SDK enablement
     * =========================================================================
     */

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

    /*
     * =========================================================================
     * Resource
     * =========================================================================
     */

    public function testServiceName(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('service-name')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SERVICE_NAME=test-service',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertSame(
            'test-service',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );
    }

    public function testResourceAttributes(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('resource-attributes')
                    ->startSpan();

                $span->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=service.version=1.2.3,deployment.environment.name=test,custom.attribute=value',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        $payload = $this->traces[0];

        self::assertSame(
            '1.2.3',
            $this->resourceAttribute($payload, 'service.version'),
        );

        self::assertSame(
            'test',
            $this->resourceAttribute(
                $payload,
                'deployment.environment.name',
            ),
        );

        self::assertSame(
            'value',
            $this->resourceAttribute(
                $payload,
                'custom.attribute',
            ),
        );
    }

    public function testServiceNameTakesPrecedenceOverResourceAttribute(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('service-name-precedence')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SERVICE_NAME=service-from-name',
            'OTEL_RESOURCE_ATTRIBUTES=service.name=service-from-resource',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertSame(
            'service-from-name',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );
    }

    public function testResourceAttributesEnvironmentValuesAreStrings(): void
    {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('environment-test')
                    ->spanBuilder('environment.resource.attributes')
                    ->startSpan()
                    ->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=deployment.environment=production,test.number=42,test.boolean=true,test.decimal=1.5',
        );

        $payload = $this->traces[0];

        self::assertSame(
            ['production'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "deployment.environment")].value.stringValue',
            ),
        );

        self::assertSame(
            ['42'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "test.number")].value.stringValue',
            ),
        );

        self::assertSame(
            ['true'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "test.boolean")].value.stringValue',
            ),
        );

        self::assertSame(
            ['1.5'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "test.decimal")].value.stringValue',
            ),
        );
    }

    public function testResourceAttributesEnvironmentVariableParsesWhitespace(): void
    {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('environment-test')
                    ->spanBuilder('environment.resource.attributes.whitespace')
                    ->startSpan()
                    ->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=deployment.environment=production, service.version=1.2.3, service.instance.id=test-instance',
        );

        $payload = $this->traces[0];

        self::assertSame(
            ['production'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "deployment.environment")].value.stringValue',
            ),
        );

        self::assertSame(
            ['1.2.3'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "service.version")].value.stringValue',
            ),
        );

        self::assertSame(
            ['test-instance'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "service.instance.id")].value.stringValue',
            ),
        );
    }

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

    /*
     * =========================================================================
     * Sampling
     * =========================================================================
     */

    public function testAlwaysOnSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('always-on')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=always_on',
        );

        $this->assertSpanNames(['always-on']);
    }

    public function testAlwaysOffSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('always-off')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=always_off',
        );

        self::assertSame([], $this->traces);
    }

    public function testTraceIdRatioSamplerZero(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('ratio-zero')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=0',
        );

        self::assertSame([], $this->traces);
    }

    public function testTraceIdRatioSamplerOne(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('ratio-one')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=1',
        );

        $this->assertSpanNames(['ratio-one']);
    }

    public function testParentBasedAlwaysOnSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('parent-based-on')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=parentbased_always_on',
        );

        $this->assertSpanNames(['parent-based-on']);
    }

    public function testParentBasedAlwaysOffSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('parent-based-off')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=parentbased_always_off',
        );

        self::assertSame([], $this->traces);
    }

    public function testParentBasedTraceIdRatioSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('parent-based-ratio')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=parentbased_traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=1',
        );

        $this->assertSpanNames(['parent-based-ratio']);
    }

    public function testParentBasedTraceIdRatioZeroDropsRootButKeepsSampledRemoteChild(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                // Root span: ratio 0 -> dropped.
                $root = $tracer->spanBuilder('dropped-root')->startSpan();
                $root->end();

                // Child of a sampled remote parent: parent decision wins.
                $remoteParent = SpanContext::create(
                    '4193e569320548f7b71d4c5a750d504c',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED,
                );

                $child = $tracer
                    ->spanBuilder('kept-child')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($remoteParent)))
                    ->startSpan();
                $child->end();
            },
            'OTEL_TRACES_SAMPLER=parentbased_traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=0',
        );

        self::assertSpanNames(['kept-child']);
    }

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
     * =========================================================================
     * LogRecord limits
     * =========================================================================
     */

    public function testLogRecordAttributeCountLimitEnvironmentVariable(): void
    {
        $this->runOTel(
            static function (): void {
                $record = new LogRecord('environment.log.attribute.count');

                $record->setAttributes([
                    'test.attribute.1' => 'one',
                    'test.attribute.2' => 'two',
                    'test.attribute.3' => 'three',
                    'test.attribute.4' => 'four',
                ]);

                Globals::loggerProvider()
                    ->getLogger('environment-test')
                    ->emit($record);
            },
            'OTEL_LOGRECORD_ATTRIBUTE_COUNT_LIMIT=2',
        );

        $attributes = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[?(@.body.stringValue == "environment.log.attribute.count")].attributes[*]',
        );

        self::assertCount(2, $attributes);
    }

    public function testLogRecordAttributeCountLimit(): void {
        $this->runOTel(
            static function (): void {
                $record = new LogRecord('log-attributes');

                $record->setAttributes([
                    'a1' => '1',
                    'a2' => '2',
                    'a3' => '3',
                ]);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit($record);
            },
            'OTEL_LOGRECORD_ATTRIBUTE_COUNT_LIMIT=2',
        );

        self::assertCount(
            2,
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[0].attributes[*]',
            ),
        );
    }

    public function testLogRecordAttributeValueLengthLimit(): void {
        $this->runOTel(
            static function (): void {
                $record = new LogRecord('log-value');

                $record->setAttributes([
                    'test.attribute' => 'abcdefghij',
                ]);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit($record);
            },
            'OTEL_LOGRECORD_ATTRIBUTE_VALUE_LENGTH_LIMIT=4',
        );

        self::assertSame(
            'abcd',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[0].attributes[?(@.key == "test.attribute")].value.stringValue',
            )[0],
        );
    }

    /*
     * =========================================================================
     * Batch LogRecord Processor
     * =========================================================================
     */

    public function testBlrpMaxExportBatchSize(): void {
        $this->runOTel(
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('test');

                for ($i = 1; $i <= 5; ++$i) {
                    $logger->emit(new LogRecord("log-{$i}"));
                }
            },
            'OTEL_BLRP_MAX_EXPORT_BATCH_SIZE=2',
            'OTEL_BLRP_MAX_QUEUE_SIZE=10',
            'OTEL_BLRP_SCHEDULE_DELAY=60000',
        );

        self::assertCount(3, $this->logs);

        self::assertCount(2, $this->logsInExport($this->logs[0]));
        self::assertCount(2, $this->logsInExport($this->logs[1]));
        self::assertCount(1, $this->logsInExport($this->logs[2]));
    }

    public function testBlrpMaxQueueSize(): void {
        $this->runOTel(
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('test');

                for ($i = 1; $i <= 5; ++$i) {
                    $logger->emit(new LogRecord("log-{$i}"));
                }
            },
            'OTEL_BLRP_MAX_QUEUE_SIZE=2',
            'OTEL_BLRP_MAX_EXPORT_BATCH_SIZE=2',
            'OTEL_BLRP_SCHEDULE_DELAY=60000',
        );

        $exported = array_sum(
            array_map(
                fn(string $payload): int => count($this->logsInExport($payload)),
                $this->logs,
            ),
        );

        self::assertLessThanOrEqual(5, $exported);
        self::assertGreaterThan(0, $exported);
    }

    public function testBlrpScheduleDelayDoesNotLoseLogsAfterFlush(): void {
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('scheduled-log'));
            },
            'OTEL_BLRP_SCHEDULE_DELAY=60000',
        );

        self::assertNotEmpty($this->logs);
    }

    public function testBlrpExportTimeout(): void {
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('timeout-log'));
            },
            'OTEL_BLRP_EXPORT_TIMEOUT=100',
        );

        self::assertIsArray($this->logs);
    }

    /*
     * =========================================================================
     * Metrics
     * =========================================================================
     */

    public function testMetricExportInterval(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('test.counter')
                    ->add(1);
            },
            'OTEL_METRIC_EXPORT_INTERVAL=100',
        );

        self::assertNotEmpty($this->metrics);

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "test.counter")]',
            ),
        );
    }

    public function testMetricExportTimeout(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('timeout.counter')
                    ->add(1);
            },
            'OTEL_METRIC_EXPORT_TIMEOUT=100',
        );

        self::assertIsArray($this->metrics);
    }

    public function testMetricsExemplarFilterAlwaysOff(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $span = $tracer
                    ->spanBuilder('exemplar')
                    ->startSpan();

                $scope = $span->activate();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('exemplar.counter')
                    ->add(1);

                $scope->detach();
                $span->end();
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=always_off',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->metrics);

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[*]..exemplars[*]',
            ),
        );
    }

    public function testMetricsExemplarFilterAlwaysOnCapturesWithoutSpan(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('no-span.counter')
                    ->add(1);
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=always_on',
        );

        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "no-span.counter")]..exemplars[*]',
        );

        /*
         * always_on captures measurements even without an active span;
         * the exemplar then carries no trace context.
         */
        self::assertCount(1, $exemplars);
        self::assertSame('1', $exemplars[0]['asInt']);
        self::assertArrayNotHasKey('traceId', $exemplars[0]);
        self::assertArrayNotHasKey('spanId', $exemplars[0]);
    }

    public function testMetricsExemplarFilterTraceBasedOnlyCapturesInSampledSpans(): void {
        $this->runOTel(
            static function (): void {
                // Measurement without an active span: no exemplar.
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('outside.counter')
                    ->add(1);

                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('tb-parent')
                    ->startSpan();

                $scope = $span->activate();

                // Measurement inside a sampled span: exemplar with trace context.
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('inside.counter')
                    ->add(1);

                $scope->detach();
                $span->end();
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=trace_based',
        );

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "outside.counter")]..exemplars[*]',
            ),
        );

        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "inside.counter")]..exemplars[*]',
        );

        self::assertCount(1, $exemplars);
        self::assertSame('1', $exemplars[0]['asInt']);
        self::assertArrayHasKey('traceId', $exemplars[0]);
        self::assertArrayHasKey('spanId', $exemplars[0]);
    }

    /*
     * =========================================================================
     * Cross-signal correlation
     * =========================================================================
     */

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
         * encodes byte fields as base64, while span payloads use hex.)
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

    /*
     * =========================================================================
     * Edge cases
     * =========================================================================
     */

    public function testEmptyEnvironmentVariableBehavesAsUnset(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('empty-env')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=',
        );

        self::assertNotEmpty($this->traces);
    }

    public function testInvalidSamplerDoesNotPreventSdkStartup(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('invalid-sampler')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=not-a-sampler',
        );

        self::assertNotEmpty($this->traces);
    }

    public function testInvalidSamplerArgumentDoesNotPreventSdkStartup(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('invalid-argument')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=not-a-number',
        );

        self::assertNotEmpty($this->traces);
    }

    /**
     * @param list<string> $expected
     */
    private function assertSpanNames(array $expected): void {
        $actual = [];

        foreach ($this->traces as $payload) {
            $actual = [
                ...$actual,
                ...$this->spanNames($payload),
            ];
        }

        self::assertSame($expected, $actual);
    }

    public function testMalformedResourceAttributesAreIgnored(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('malformed-attrs')
                    ->startSpan();

                $span->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=not-a-pair,good.key=good-value',
        );

        self::assertNotEmpty($this->traces);

        /*
         * The entry without a "=" separator is dropped...
         */
        $keys = $this->path(
            $this->combinedTracePayload(),
            '$.resourceSpans[*].resource.attributes[*].key',
        );

        self::assertNotContains('not-a-pair', $keys);

        /*
         * ...while the valid entry in the same list is applied.
         */
        self::assertSame(
            'good-value',
            $this->resourceAttribute($this->combinedTracePayload(), 'good.key'),
        );
    }

    public function testUnknownPropagatorDisablesPropagationWithoutBreakingStartup(): void {
        $output = $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('unknown-propagator')
                    ->startSpan();

                $span->end();

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
            'OTEL_PROPAGATORS=doesnotexist',
        );

        /*
         * The SDK still starts and exports spans...
         */
        self::assertNotEmpty($this->traces);

        /*
         * ...but the unknown propagator results in no propagation at all.
         */
        self::assertSame(
            [],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function spanIdByName(string $name): string {
        $values = $this->path(
            $this->combinedTracePayload(),
            sprintf(
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "%s")].spanId',
                $name,
            ),
        );

        self::assertCount(1, $values);

        return $values[0];
    }

    private function spanParentIdByName(string $name): string {
        $values = $this->path(
            $this->combinedTracePayload(),
            sprintf(
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "%s")].parentSpanId',
                $name,
            ),
        );

        self::assertCount(1, $values);

        return $values[0];
    }

}

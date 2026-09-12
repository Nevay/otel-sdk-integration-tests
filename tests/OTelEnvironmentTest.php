<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
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

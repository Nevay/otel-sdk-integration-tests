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
use function sprintf;

final class OTelConfigFileTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Basic configuration
     * =========================================================================
     */

    public function testConfigFileConfiguresTracerProvider(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: config-file-service

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
                    ->spanBuilder('config-file')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertNotEmpty($this->traces);

        self::assertSame(
            'config-file-service',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );

        self::assertSame(
            ['config-file'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testSignalsCanBeConfiguredIndependently(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: all-signals

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('all-signals-span')
                    ->startSpan();

                $span->end();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('all-signals.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('all-signals-log'));

                Globals::tracerProvider()->forceFlush();
                Globals::meterProvider()->forceFlush();
                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);

        self::assertSame(
            'all-signals',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );

        self::assertSame(
            ['all-signals-span'],
            $this->spanNames($this->traces[0]),
        );

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "all-signals.counter")]',
            ),
        );

        self::assertSame(
            'all-signals-log',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            )[0],
        );
    }

    /*
     * =========================================================================
     * Resource attributes
     * =========================================================================
     */

    public function testResourceAttributes(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: config-test
                - name: service.version
                  value: "1.2.3"
                - name: deployment.environment.name
                  value: test
                - name: custom.attribute
                  value: value

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
                    ->spanBuilder('resource-attributes')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        $payload = $this->traces[0];

        self::assertSame(
            'config-test',
            $this->resourceAttribute($payload, 'service.name'),
        );

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

    public function testResourceAttributesList(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes_list: "service.name=config-test,service.version=1.2.3"

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
                    ->spanBuilder('resource-attributes-list')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        $payload = $this->traces[0];

        self::assertSame(
            'config-test',
            $this->resourceAttribute($payload, 'service.name'),
        );

        self::assertSame(
            '1.2.3',
            $this->resourceAttribute($payload, 'service.version'),
        );
    }

    public function testExplicitResourceAttributesOverrideAttributesList(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes_list: "service.name=from-list,service.version=1.0"
              attributes:
                - name: service.name
                  value: from-attributes
                - name: service.version
                  value: "2.0"

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
                    ->spanBuilder('resource-precedence')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        $payload = $this->traces[0];

        self::assertSame(
            'from-attributes',
            $this->resourceAttribute($payload, 'service.name'),
        );

        self::assertSame(
            '2.0',
            $this->resourceAttribute($payload, 'service.version'),
        );
    }

    /*
     * =========================================================================
     * SDK disabled
     * =========================================================================
     */

    public function testDisabledConfigurationDisablesAllSignals(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            disabled: true

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('disabled')
                    ->startSpan();

                $span->end();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('disabled.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('disabled'));

                Globals::tracerProvider()->forceFlush();
                Globals::meterProvider()->forceFlush();
                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertSame([], $this->traces);
        self::assertSame([], $this->metrics);
        self::assertSame([], $this->logs);
    }

    /*
     * =========================================================================
     * Propagators
     * =========================================================================
     */

    public function testConfigFilePropagatorsConfigureTraceContextAndBaggage(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - tracecontext:
                - baggage:
            YAML,
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

    public function testConfigFileCanConfigureOnlyTraceContextPropagator(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - tracecontext:
            YAML,
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
        );

        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($result['valid']);

        self::assertSame(
            '0123456789abcdef0123456789abcdef',
            $result['traceId'],
        );

        self::assertNull($result['baggage']);
    }

    public function testConfigFilePropagatorsAreUsedForInjection(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - tracecontext:
                - baggage:
            YAML,
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

    /*
     * =========================================================================
     * Configurators
     * =========================================================================
     */

    public function testConfiguratorDefaultsAreEnabledWhenDefaultConfigIsOmitted(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              tracer_configurator/development:
                tracers:
                  - name: disabled.tracer
                    config:
                      enabled: false

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            meter_provider:
              meter_configurator/development:
                meters:
                  - name: disabled.meter
                    config:
                      enabled: false

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: disabled.logger
                    config:
                      enabled: false

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $tracerProvider = Globals::tracerProvider();

                $tracerProvider
                    ->getTracer('normal.tracer')
                    ->spanBuilder('normal')
                    ->startSpan()
                    ->end();

                $tracerProvider
                    ->getTracer('disabled.tracer')
                    ->spanBuilder('disabled')
                    ->startSpan()
                    ->end();

                $meterProvider = Globals::meterProvider();

                $meterProvider
                    ->getMeter('normal.meter')
                    ->createCounter('normal')
                    ->add(1);

                $meterProvider
                    ->getMeter('disabled.meter')
                    ->createCounter('disabled')
                    ->add(1);

                $loggerProvider = Globals::loggerProvider();

                $loggerProvider
                    ->getLogger('normal.logger')
                    ->emit(new LogRecord('normal'));

                $loggerProvider
                    ->getLogger('disabled.logger')
                    ->emit(new LogRecord('disabled'));

                Globals::tracerProvider()->forceFlush();
                Globals::meterProvider()->forceFlush();
                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['normal'],
            $this->spanNames($this->traces[0]),
        );

        $metricNames = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name',
        );

        self::assertContains('normal', $metricNames);
        self::assertNotContains('disabled', $metricNames);
    }

    public function testConfiguratorsSupportAsteriskWildcard(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              tracer_configurator/development:
                default_config:
                  enabled: false
                tracers:
                  - name: app*
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            meter_provider:
              meter_configurator/development:
                default_config:
                  enabled: false
                meters:
                  - name: app*
                    config:
                      enabled: true

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: app*
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $tracerProvider = Globals::tracerProvider();

                $tracerProvider
                    ->getTracer('app')
                    ->spanBuilder('wildcard.tracer.app')
                    ->startSpan()
                    ->end();

                $tracerProvider
                    ->getTracer('app.foo')
                    ->spanBuilder('wildcard.tracer.app.foo')
                    ->startSpan()
                    ->end();

                $tracerProvider
                    ->getTracer('other')
                    ->spanBuilder('wildcard.tracer.other')
                    ->startSpan()
                    ->end();

                $meterProvider = Globals::meterProvider();

                $meterProvider
                    ->getMeter('app')
                    ->createCounter('wildcard.meter.app')
                    ->add(1);

                $meterProvider
                    ->getMeter('app.foo')
                    ->createCounter('wildcard.meter.app.foo')
                    ->add(1);

                $meterProvider
                    ->getMeter('other')
                    ->createCounter('wildcard.meter.other')
                    ->add(1);

                $loggerProvider = Globals::loggerProvider();

                $loggerProvider
                    ->getLogger('app')
                    ->emit(new LogRecord('wildcard.logger.app'));

                $loggerProvider
                    ->getLogger('app.foo')
                    ->emit(new LogRecord('wildcard.logger.app.foo'));

                $loggerProvider
                    ->getLogger('other')
                    ->emit(new LogRecord('wildcard.logger.other'));

                Globals::tracerProvider()->forceFlush();
                Globals::meterProvider()->forceFlush();
                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertContains(
            'wildcard.tracer.app',
            $this->spanNames($this->traces[0]),
        );

        self::assertContains(
            'wildcard.tracer.app.foo',
            $this->spanNames($this->traces[0]),
        );

        self::assertNotContains(
            'wildcard.tracer.other',
            $this->spanNames($this->traces[0]),
        );

        $metricNames = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name',
        );

        self::assertContains('wildcard.meter.app', $metricNames);
        self::assertContains('wildcard.meter.app.foo', $metricNames);
        self::assertNotContains('wildcard.meter.other', $metricNames);

        $logBodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertContains('wildcard.logger.app', $logBodies);
        self::assertContains('wildcard.logger.app.foo', $logBodies);
        self::assertNotContains('wildcard.logger.other', $logBodies);
    }

    public function testConfiguratorConfigurationIsIsolatedBetweenSignals(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              tracer_configurator/development:
                default_config:
                  enabled: false
                tracers:
                  - name: enabled.tracer
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            meter_provider:
              meter_configurator/development:
                default_config:
                  enabled: true
                meters:
                  - name: disabled.meter
                    config:
                      enabled: false

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: enabled.logger
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('enabled.tracer')
                    ->spanBuilder('config.isolation.tracer')
                    ->startSpan()
                    ->end();

                Globals::tracerProvider()
                    ->getTracer('disabled.tracer')
                    ->spanBuilder('config.isolation.disabled.tracer')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('enabled.meter')
                    ->createCounter('config.isolation.enabled.meter')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter('disabled.meter')
                    ->createCounter('config.isolation.disabled.meter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('enabled.logger')
                    ->emit(new LogRecord('config.isolation.enabled.logger'));

                Globals::loggerProvider()
                    ->getLogger('disabled.logger')
                    ->emit(new LogRecord('config.isolation.disabled.logger'));

                Globals::tracerProvider()->forceFlush();
                Globals::meterProvider()->forceFlush();
                Globals::loggerProvider()->forceFlush();
            },
        );

        $spanNames = $this->spanNames($this->traces[0]);

        self::assertContains('config.isolation.tracer', $spanNames);
        self::assertNotContains('config.isolation.disabled.tracer', $spanNames);

        $metricNames = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name',
        );

        self::assertContains('config.isolation.enabled.meter', $metricNames);
        self::assertNotContains('config.isolation.disabled.meter', $metricNames);

        $logBodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertContains('config.isolation.enabled.logger', $logBodies);
        self::assertNotContains('config.isolation.disabled.logger', $logBodies);
    }

    /*
     * =========================================================================
     * Sampling
     * =========================================================================
     */

    public function testAlwaysOnSampler(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                always_on:

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('always-on')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['always-on'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testAlwaysOffSampler(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                always_off:

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('always-off')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame([], $this->traces);
    }

    public function testTraceIdRatioBasedSampler(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                trace_id_ratio_based:
                  ratio: 1.0

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('ratio-one')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['ratio-one'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testTraceIdRatioBasedSamplerZero(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                trace_id_ratio_based:
                  ratio: 0.0

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('ratio-zero')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame([], $this->traces);
    }

    public function testParentBasedSampler(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                parent_based:
                  root:
                    always_on:

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('parent-based')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['parent-based'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testParentBasedSamplerRootAlwaysOff(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                parent_based:
                  root:
                    always_off:

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('parent-based-off')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame([], $this->traces);
    }

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

                Globals::tracerProvider()->forceFlush();
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

                Globals::tracerProvider()->forceFlush();
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

    /*
     * =========================================================================
     * Batch Span Processor
     * =========================================================================
     */

    public function testBatchSpanProcessorMaxExportBatchSize(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    schedule_delay: 60000
                    max_queue_size: 10
                    max_export_batch_size: 2
                    export_timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                for ($i = 1; $i <= 5; ++$i) {
                    $span = $tracer
                        ->spanBuilder("span-{$i}")
                        ->startSpan();

                    $span->end();
                }

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertCount(3, $this->traces);

        self::assertCount(
            2,
            $this->spansInExport($this->traces[0]),
        );

        self::assertCount(
            2,
            $this->spansInExport($this->traces[1]),
        );

        self::assertCount(
            1,
            $this->spansInExport($this->traces[2]),
        );
    }

    public function testBatchSpanProcessorScheduleDelay(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    schedule_delay: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('scheduled')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertNotEmpty($this->traces);

        self::assertSame(
            ['scheduled'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testBatchSpanProcessorExportTimeoutIsAccepted(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    export_timeout: 100
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('timeout')
                    ->startSpan();

                $span->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertIsArray($this->traces);
    }

    /*
     * =========================================================================
     * Tracer configurator
     * =========================================================================
     */

    public function testTracerConfiguratorCanDisableDefaultTracersAndEnableMatchingTracer(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              tracer_configurator/development:
                default_config:
                  enabled: false
                tracers:
                  - name: enabled.tracer
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $disabled = Globals::tracerProvider()
                    ->getTracer('disabled.tracer')
                    ->spanBuilder('disabled-span')
                    ->startSpan();

                $disabled->end();

                $enabled = Globals::tracerProvider()
                    ->getTracer('enabled.tracer')
                    ->spanBuilder('enabled-span')
                    ->startSpan();

                $enabled->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['enabled-span'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testTracerConfiguratorSupportsWildcardMatching(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              tracer_configurator/development:
                default_config:
                  enabled: false
                tracers:
                  - name: application.*
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider();

                $enabled = $tracer
                    ->getTracer('application.http')
                    ->spanBuilder('enabled')
                    ->startSpan();

                $enabled->end();

                $disabled = $tracer
                    ->getTracer('library.http')
                    ->spanBuilder('disabled')
                    ->startSpan();

                $disabled->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['enabled'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testTracerConfiguratorCanDisableMatchingTracer(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              tracer_configurator/development:
                default_config:
                  enabled: true
                tracers:
                  - name: noisy.tracer
                    config:
                      enabled: false

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider();

                $normal = $tracer
                    ->getTracer('normal.tracer')
                    ->spanBuilder('normal')
                    ->startSpan();

                $normal->end();

                $noisy = $tracer
                    ->getTracer('noisy.tracer')
                    ->spanBuilder('noisy')
                    ->startSpan();

                $noisy->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['normal'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testTracerConfiguratorQuestionMarkWildcard(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              tracer_configurator/development:
                default_config:
                  enabled: false
                tracers:
                  - name: app.?
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracerProvider = Globals::tracerProvider();

                $enabled = $tracerProvider
                    ->getTracer('app.a')
                    ->spanBuilder('enabled')
                    ->startSpan();

                $enabled->end();

                $disabled = $tracerProvider
                    ->getTracer('app.ab')
                    ->spanBuilder('disabled')
                    ->startSpan();

                $disabled->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['enabled'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testTracerConfiguratorMatchingIsCaseSensitive(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              tracer_configurator/development:
                default_config:
                  enabled: false
                tracers:
                  - name: app.tracer
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracerProvider = Globals::tracerProvider();

                $tracerProvider
                    ->getTracer('app.tracer')
                    ->spanBuilder('lowercase')
                    ->startSpan()
                    ->end();

                $tracerProvider
                    ->getTracer('APP.TRACER')
                    ->spanBuilder('uppercase')
                    ->startSpan()
                    ->end();

                Globals::tracerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['lowercase'],
            $this->spanNames($this->traces[0]),
        );
    }

    /*
     * =========================================================================
     * Metric reader & exemplars
     * =========================================================================
     */

    public function testPeriodicMetricReader(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.counter')
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        self::assertNotEmpty($this->metrics);

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "config.counter")]',
            ),
        );
    }

    public function testMetricsExemplarFilterAlwaysOff(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              exemplar_filter: always_off

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                $span = $tracer
                    ->spanBuilder('exemplar')
                    ->startSpan();

                $scope = $span->activate();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('exemplar.counter')
                    ->add(1);

                $scope->detach();
                $span->end();

                Globals::meterProvider()->forceFlush();
                Globals::tracerProvider()->forceFlush();
            },
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
     * Meter configurator
     * =========================================================================
     */

    public function testMeterConfiguratorCanDisableDefaultMetersAndEnableMatchingMeter(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              meter_configurator/development:
                default_config:
                  enabled: false
                meters:
                  - name: enabled.meter
                    config:
                      enabled: true

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('disabled.meter')
                    ->createCounter('disabled.counter')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter('enabled.meter')
                    ->createCounter('enabled.counter')
                    ->add(2);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "disabled.counter")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "enabled.counter")]',
            ),
        );
    }

    public function testMeterConfiguratorSupportsWildcardMatching(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              meter_configurator/development:
                default_config:
                  enabled: false
                meters:
                  - name: application.*
                    config:
                      enabled: true

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('application.http')
                    ->createCounter('enabled.counter')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter('library.http')
                    ->createCounter('disabled.counter')
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "enabled.counter")]',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "disabled.counter")]',
            ),
        );
    }

    public function testMeterConfiguratorCanDisableMatchingMeterWithDefaultEnabled(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              meter_configurator/development:
                default_config:
                  enabled: true
                meters:
                  - name: noisy.meter
                    config:
                      enabled: false

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('normal.meter')
                    ->createCounter('normal.counter')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter('noisy.meter')
                    ->createCounter('noisy.counter')
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "normal.counter")]',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "noisy.counter")]',
            ),
        );
    }

    public function testMeterConfiguratorQuestionMarkWildcard(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              meter_configurator/development:
                default_config:
                  enabled: false
                meters:
                  - name: app.?
                    config:
                      enabled: true

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $meterProvider = Globals::meterProvider();

                $meterProvider
                    ->getMeter('app.a')
                    ->createCounter('enabled')
                    ->add(1);

                $meterProvider
                    ->getMeter('app.ab')
                    ->createCounter('disabled')
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['enabled'],
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name',
            ),
        );
    }

    public function testMeterConfiguratorMatchingIsCaseSensitive(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              meter_configurator/development:
                default_config:
                  enabled: false
                meters:
                  - name: app.meter
                    config:
                      enabled: true

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $meterProvider = Globals::meterProvider();

                $meterProvider
                    ->getMeter('app.meter')
                    ->createCounter('lowercase')
                    ->add(1);

                $meterProvider
                    ->getMeter('APP.METER')
                    ->createCounter('uppercase')
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['lowercase'],
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name',
            ),
        );
    }

    /*
     * =========================================================================
     * Views
     * =========================================================================
     */

    public function testViewSelectsByInstrumentTypeAndUnit(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_type: histogram
                    unit: ms
                  stream:
                    name: latency.selected
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createHistogram('latency', 'ms')
                    ->record(10);

                $meter
                    ->createHistogram('latency.bytes', 'bytes')
                    ->record(10);

                $meter
                    ->createCounter('latency.counter', 'ms')
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.selected")]',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.bytes")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.counter")]',
            ),
        );
    }

    public function testViewSelectsByMeterNameVersionAndSchemaUrl(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                    meter_name: selected-meter
                    meter_version: "1.2.3"
                    meter_schema_url: https://example.com/schema
                  stream:
                    name: selected.requests
            YAML,
            static function (): void {
                $selected = Globals::meterProvider()->getMeter(
                    'selected-meter',
                    '1.2.3',
                    'https://example.com/schema',
                );

                $selected
                    ->createCounter('requests')
                    ->add(1);

                $wrongVersion = Globals::meterProvider()->getMeter(
                    'selected-meter',
                    '9.9.9',
                    'https://example.com/schema',
                );

                $wrongVersion
                    ->createCounter('requests')
                    ->add(1);

                $wrongMeter = Globals::meterProvider()->getMeter(
                    'other-meter',
                    '1.2.3',
                    'https://example.com/schema',
                );

                $wrongMeter
                    ->createCounter('requests')
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.requests")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.requests")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[?(@.scope.name == "selected-meter")].metrics[?(@.name == "selected.requests")]',
            ),
        );
    }

    public function testViewSelectsByMeterName(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.meter_name.requests
                    meter_name: selected-meter
                  stream:
                    name: selected.meter_name.requests
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('selected-meter')
                    ->createCounter('view.meter_name.requests')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter('other-meter')
                    ->createCounter('view.meter_name.requests')
                    ->add(2);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_name.requests")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_name.requests")]',
            ),
        );

        self::assertSame(
            '1',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_name.requests")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertSame(
            '2',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_name.requests")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewSelectsByMeterVersion(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.meter_version.requests
                    meter_name: versioned-meter
                    meter_version: "1.0.0"
                  stream:
                    name: selected.meter_version.requests
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('versioned-meter', '1.0.0')
                    ->createCounter('view.meter_version.requests')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter('versioned-meter', '2.0.0')
                    ->createCounter('view.meter_version.requests')
                    ->add(2);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_version.requests")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_version.requests")]',
            ),
        );

        self::assertSame(
            '1',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_version.requests")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertSame(
            '2',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_version.requests")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewSelectsByMeterSchemaUrl(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.meter_schema.requests
                    meter_name: schema-meter
                    meter_schema_url: https://example.test/schema/one
                  stream:
                    name: selected.meter_schema.requests
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter(
                        'schema-meter',
                        '1.0.0',
                        'https://example.test/schema/one',
                    )
                    ->createCounter('view.meter_schema.requests')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter(
                        'schema-meter',
                        '1.0.0',
                        'https://example.test/schema/two',
                    )
                    ->createCounter('view.meter_schema.requests')
                    ->add(2);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_schema.requests")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_schema.requests")]',
            ),
        );

        self::assertSame(
            '1',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_schema.requests")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertSame(
            '2',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_schema.requests")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewSelectorRequiresAllSpecifiedCriteriaToMatch(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: latency
                    instrument_type: histogram
                    unit: ms
                  stream:
                    name: selected.latency
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                // Matches name + type + unit.
                $meter
                    ->createHistogram('latency', 'ms')
                    ->record(10);

                // Matches name + type, but not unit.
                $meter
                    ->createHistogram('latency.seconds', 's')
                    ->record(10);

                // Matches name + unit, but not type.
                $meter
                    ->createCounter('latency.counter', 'ms')
                    ->add(10);

                // Matches type + unit, but not name.
                $meter
                    ->createHistogram('other', 'ms')
                    ->record(10);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.latency")]',
            ),
        );

        // Non-matching instruments continue to be exported normally.
        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.seconds")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.counter")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "other")]',
            ),
        );

        self::assertSame(
            10,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.latency")].histogram.dataPoints[*].sum',
            )[0],
        );
    }

    public function testViewSelectorMatchesAllSpecifiedInstrumentAndMeterCriteria(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.all.criteria
                    instrument_type: counter
                    unit: requests
                    meter_name: selected-meter
                    meter_version: "1.2.3"
                    meter_schema_url: https://example.test/schema
                  stream:
                    name: view.all.criteria.selected
            YAML,
            static function (): void {
                $selected = Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    );

                $selected
                    ->createCounter('view.all.criteria', 'requests')
                    ->add(1);

                // Different instrument name.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    )
                    ->createCounter('view.all.criteria.other-name', 'requests')
                    ->add(2);

                // Different instrument type.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    )
                    ->createHistogram('view.all.criteria', 'requests')
                    ->record(3);

                // Different unit.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    )
                    ->createCounter('view.all.criteria', 'other')
                    ->add(4);

                // Different meter name.
                Globals::meterProvider()
                    ->getMeter(
                        'other-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    )
                    ->createCounter('view.all.criteria', 'requests')
                    ->add(5);

                // Different meter version.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '9.9.9',
                        'https://example.test/schema',
                    )
                    ->createCounter('view.all.criteria', 'requests')
                    ->add(6);

                // Different schema URL.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/other-schema',
                    )
                    ->createCounter('view.all.criteria', 'requests')
                    ->add(7);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.all.criteria.selected")]',
            ),
        );

        self::assertSame(
            ['1'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.all.criteria.selected")].sum.dataPoints[*].asInt',
            ),
        );

        // Every non-matching instrument remains exported under its original name.
        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.all.criteria.other-name")]',
            ),
        );

        // The original instrument name has multiple non-matching instruments,
        // so verify their values rather than asserting a single metric.
        $originalMetricValues = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.all.criteria")].sum.dataPoints[*].asInt',
        );

        self::assertContains('4', $originalMetricValues);
        self::assertContains('5', $originalMetricValues);
        self::assertContains('6', $originalMetricValues);
        self::assertContains('7', $originalMetricValues);
    }

    public function testViewWithEmptySelectorMatchesEveryInstrument(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector: {}
                  stream:
                    name: all.instruments
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('counter')
                    ->add(1, ['test.instrument' => 'counter']);

                $meter
                    ->createHistogram('histogram')
                    ->record(2, ['test.instrument' => 'histogram']);

                $meter
                    ->createGauge('gauge')
                    ->record(3, ['test.instrument' => 'gauge']);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        // An empty selector matches every instrument, including instruments
        // created by installed auto-instrumentation. Therefore, don't assert
        // an exact number of "all.instruments" metrics/data points.

        $counterTestAttributes = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "all.instruments")].sum.dataPoints[*].attributes[?(@.key == "test.instrument")].value.stringValue',
        );

        self::assertContains('counter', $counterTestAttributes);

        $histogramTestAttributes = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "all.instruments")].histogram.dataPoints[*].attributes[?(@.key == "test.instrument")].value.stringValue',
        );

        self::assertContains('histogram', $histogramTestAttributes);

        $gaugeTestAttributes = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "all.instruments")].gauge.dataPoints[*].attributes[?(@.key == "test.instrument")].value.stringValue',
        );

        self::assertContains('gauge', $gaugeTestAttributes);
    }

    public function testViewFiltersAttributeKeys(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    attribute_keys:
                      included:
                        - http.method
                        - http.route
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests')
                    ->add(
                        1,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/users',
                            'http.status_code' => 200,
                        ],
                    );

                Globals::meterProvider()->forceFlush();
            },
        );

        $attributes = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[*]',
        );

        self::assertCount(2, $attributes);

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "http.method")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "http.route")]',
            ),
        );

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "http.status_code")]',
            ),
        );
    }

    public function testViewAttributeKeysSupportIncludeAndExcludePatterns(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    attribute_keys:
                      included:
                        - http.*
                      excluded:
                        - http.user_agent
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests')
                    ->add(
                        1,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/users',
                            'http.user_agent' => 'test-agent',
                            'other.attribute' => 'ignored',
                        ],
                    );

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $keys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[*].key',
        );

        self::assertCount(2, $keys);
        self::assertContains('http.method', $keys);
        self::assertContains('http.route', $keys);
        self::assertNotContains('http.user_agent', $keys);
        self::assertNotContains('other.attribute', $keys);
    }

    public function testViewExcludedAttributesTakePrecedenceOverIncludedAttributes(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.attribute.precedence
                  stream:
                    attribute_keys:
                      included:
                        - http.*
                      excluded:
                        - http.user_agent
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('view.attribute.precedence')
                    ->add(
                        1,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/test',
                            'http.user_agent' => 'test-agent',
                            'other.attribute' => 'ignored',
                        ],
                    );

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $keys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.attribute.precedence")].sum.dataPoints[*].attributes[*].key',
        );

        self::assertContains('http.method', $keys);
        self::assertContains('http.route', $keys);
        self::assertNotContains('http.user_agent', $keys);
        self::assertNotContains('other.attribute', $keys);
    }

    public function testViewAttributeFilteringOccursBeforeCardinalityLimit(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.cardinality.after.filtering
                  stream:
                    attribute_keys:
                      included:
                        - region
                    aggregation_cardinality_limit: 2
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('view.cardinality.after.filtering');

                $counter->add(
                    1,
                    [
                        'region' => 'eu',
                        'request_id' => 'request-1',
                    ],
                );

                $counter->add(
                    1,
                    [
                        'region' => 'eu',
                        'request_id' => 'request-2',
                    ],
                );

                $counter->add(
                    1,
                    [
                        'region' => 'us',
                        'request_id' => 'request-3',
                    ],
                );

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $dataPoints = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.cardinality.after.filtering")].sum.dataPoints[*]',
        );

        self::assertCount(2, $dataPoints);

        $regions = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.cardinality.after.filtering")].sum.dataPoints[*].attributes[?(@.key == "region")].value.stringValue',
        );

        self::assertContains('eu', $regions);
        self::assertContains('us', $regions);

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.cardinality.after.filtering")].sum.dataPoints[*].attributes[?(@.key == "otel.metric.overflow")]',
            ),
        );

        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.cardinality.after.filtering")].sum.dataPoints[*].asInt',
        );

        self::assertContains('2', $values);
        self::assertContains('1', $values);
    }

    public function testViewExplicitDefaultAggregationUsesInstrumentKind(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.default.counter
                  stream:
                    aggregation:
                      default:

                - selector:
                    instrument_name: view.default.gauge
                  stream:
                    aggregation:
                      default:

                - selector:
                    instrument_name: view.default.histogram
                  stream:
                    aggregation:
                      default:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('view.default.counter')
                    ->add(5);

                $meter
                    ->createGauge('view.default.gauge')
                    ->record(7);

                $meter
                    ->createHistogram('view.default.histogram')
                    ->record(9);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.counter")].sum',
            ),
        );

        self::assertSame(
            '5',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.counter")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.gauge")].gauge',
            ),
        );

        self::assertSame(
            '7',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.gauge")].gauge.dataPoints[*].asInt',
            )[0],
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.histogram")].histogram',
            ),
        );

        self::assertSame(
            '1',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.histogram")].histogram.dataPoints[*].count',
            )[0],
        );

        self::assertSame(
            9,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.histogram")].histogram.dataPoints[*].sum',
            )[0],
        );
    }

    public function testViewUsesExplicitBucketHistogramAggregation(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: latency
                  stream:
                    aggregation:
                      explicit_bucket_histogram:
                        boundaries:
                          - 10
                          - 100
                        record_min_max: true
            YAML,
            static function (): void {
                $histogram = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createHistogram('latency', 'ms');

                $histogram->record(5);
                $histogram->record(50);
                $histogram->record(150);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertSame(
            [10, 100],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")].histogram.dataPoints[*].explicitBounds',
            )[0],
        );

        self::assertSame(
            ['1', '1', '1'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")].histogram.dataPoints[*].bucketCounts',
            )[0],
        );

        self::assertSame(
            5,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")].histogram.dataPoints[*].min',
            )[0],
        );

        self::assertSame(
            150,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")].histogram.dataPoints[*].max',
            )[0],
        );
    }

    public function testViewCanDropAnInstrument(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: noisy.requests
                  stream:
                    aggregation:
                      drop:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('noisy.requests')
                    ->add(10);

                $meter
                    ->createCounter('normal.requests')
                    ->add(20);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "noisy.requests")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "normal.requests")]',
            ),
        );
    }

    public function testViewSupportsSumAndLastValueAggregations(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: counter.sum
                  stream:
                    aggregation:
                      sum:

                - selector:
                    instrument_name: gauge.last
                  stream:
                    aggregation:
                      last_value:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $counter = $meter->createCounter('counter.sum');
                $counter->add(2);
                $counter->add(3);

                $gauge = $meter->createGauge('gauge.last');
                $gauge->record(2);
                $gauge->record(3);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $sumMetrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "counter.sum")]',
        );

        self::assertCount(1, $sumMetrics);

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "counter.sum")].sum',
            ),
        );

        self::assertSame(
            '5',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "counter.sum")].sum.dataPoints[*].asInt',
            )[0],
        );

        $lastValueMetrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "gauge.last")]',
        );

        self::assertCount(1, $lastValueMetrics);

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "gauge.last")].gauge',
            ),
        );

        self::assertSame(
            '3',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "gauge.last")].gauge.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewAggregationCardinalityLimitUsesOverflowSeries(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    aggregation_cardinality_limit: 2
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests');

                $counter->add(1, ['region' => 'eu']);
                $counter->add(2, ['region' => 'us']);
                $counter->add(3, ['region' => 'ap']);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $dataPoints = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*]',
        );

        // Two normal series plus the overflow series.
        self::assertCount(3, $dataPoints);

        $regions = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "region")].value.stringValue',
        );

        self::assertCount(2, $regions);
        self::assertContains('eu', $regions);
        self::assertContains('us', $regions);

        self::assertSame(
            [true],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "otel.metric.overflow")].value.boolValue',
            ),
        );

        // Every measurement must be represented exactly once.
        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].asInt',
        );

        self::assertCount(3, $values);
        self::assertContains('1', $values);
        self::assertContains('2', $values);
        self::assertContains('3', $values);
    }

    public function testViewAggregationPreservesInstrumentAttributes(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    aggregation:
                      sum:
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests');

                $counter->add(1, ['http.method' => 'GET']);
                $counter->add(2, ['http.method' => 'POST']);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $dataPoints = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*]',
        );

        self::assertCount(2, $dataPoints);

        $methods = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "http.method")].value.stringValue',
        );

        self::assertCount(2, $methods);
        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);

        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].asInt',
        );

        self::assertCount(2, $values);
        self::assertContains('1', $values);
        self::assertContains('2', $values);
    }

    public function testViewRenamesMetricAndChangesDescription(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    name: http.server.requests
                    description: HTTP server request count
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests')
                    ->add(3);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.server.requests")]',
            ),
        );

        self::assertSame(
            ['HTTP server request count'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.server.requests")].description',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")]',
            ),
        );
    }

    public function testViewPreservesOriginalNameAndDescriptionWhenOmitted(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    aggregation:
                      last_value:
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createGauge(
                        'requests',
                        '1',
                        'Original request description',
                    )
                    ->record(42);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        // Other metrics may be exported by installed auto-instrumentation,
        // so only inspect the metric selected by this test.
        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")]',
        );

        self::assertCount(1, $metrics);

        self::assertSame(
            ['requests'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].name',
            ),
        );

        self::assertSame(
            ['Original request description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].description',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].gauge',
            ),
        );

        self::assertSame(
            '42',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].gauge.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewCanOverrideDescriptionWhilePreservingOriginalName(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    description: Overridden request description
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter(
                        'requests',
                        '1',
                        'Original request description',
                    )
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")]',
        );

        self::assertCount(1, $metrics);

        self::assertSame(
            ['requests'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].name',
            ),
        );

        self::assertSame(
            ['Overridden request description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].description',
            ),
        );
    }

    public function testViewCanOverrideNameWhilePreservingOriginalDescription(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    name: http.requests
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter(
                        'requests',
                        '1',
                        'Original request description',
                    )
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.requests")]',
        );

        self::assertCount(1, $metrics);

        self::assertSame(
            ['http.requests'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.requests")].name',
            ),
        );

        self::assertSame(
            ['Original request description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.requests")].description',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")]',
            ),
        );
    }

    public function testInstrumentWithoutMatchingViewRemainsUnchanged(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: selected
                  stream:
                    name: selected.renamed
                    description: Selected description
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter(
                        'selected',
                        '1',
                        'Selected original description',
                    )
                    ->add(5);

                $meter
                    ->createCounter(
                        'unselected',
                        '1',
                        'Unselected original description',
                    )
                    ->add(7);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.renamed")]',
            ),
        );

        self::assertSame(
            ['Selected description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.renamed")].description',
            ),
        );

        // The instrument that doesn't match the View is still exported
        // with its original name and description.
        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "unselected")]',
            ),
        );

        self::assertSame(
            ['Unselected original description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "unselected")].description',
            ),
        );

        self::assertSame(
            '7',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "unselected")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testMultipleMatchingViewsProduceMultipleStreams(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    name: requests.total
                    attribute_keys:
                      excluded:
                        - http.method

                - selector:
                    instrument_name: requests
                  stream:
                    name: requests.by_method
                    attribute_keys:
                      included:
                        - http.method
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests');

                $counter->add(1, ['http.method' => 'GET']);
                $counter->add(2, ['http.method' => 'POST']);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.total")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.by_method")]',
            ),
        );

        self::assertSame(
            '3',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.total")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertCount(
            2,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.by_method")].sum.dataPoints[*]',
            ),
        );

        self::assertSame(
            ['GET', 'POST'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.by_method")].sum.dataPoints[*].attributes[?(@.key == "http.method")].value.stringValue',
            ),
        );
    }

    public function testMultipleMatchingViewsApplyTheirConfigurationsIndependently(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.independent.requests
                  stream:
                    name: view.independent.sum
                    aggregation:
                      sum:

                - selector:
                    instrument_name: view.independent.requests
                  stream:
                    name: view.independent.by_method
                    attribute_keys:
                      included:
                        - http.method
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('view.independent.requests');

                $counter->add(
                    1,
                    [
                        'http.method' => 'GET',
                        'http.route' => '/users',
                    ],
                );

                $counter->add(
                    2,
                    [
                        'http.method' => 'POST',
                        'http.route' => '/users',
                    ],
                );

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.sum")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.by_method")]',
            ),
        );

        // The first View only changes aggregation, so its stream retains both
        // original attributes and therefore has two distinct data points.
        $sumDataPoints = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.sum")].sum.dataPoints[*]',
        );

        self::assertCount(2, $sumDataPoints);

        $sumKeys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.sum")].sum.dataPoints[*].attributes[*].key',
        );

        self::assertContains('http.method', $sumKeys);
        self::assertContains('http.route', $sumKeys);

        // The second View only retains http.method.
        $filteredKeys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.by_method")].sum.dataPoints[*].attributes[*].key',
        );

        self::assertContains('http.method', $filteredKeys);
        self::assertNotContains('http.route', $filteredKeys);

        $methods = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.by_method")].sum.dataPoints[*].attributes[?(@.key == "http.method")].value.stringValue',
        );

        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);

        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.by_method")].sum.dataPoints[*].asInt',
        );

        self::assertContains('1', $values);
        self::assertContains('2', $values);
    }

    public function testMatchAllDropViewCanBeUsedAsDefaultWithSpecificView(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream: {}

                - selector:
                    instrument_name: "*"
                  stream:
                    aggregation:
                      drop:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('requests')
                    ->add(1);

                $meter
                    ->createCounter('other')
                    ->add(1);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertSame(
            ['requests'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "other")]',
            ),
        );
    }

    public function testViewIsAppliedBeforeMultipleMetricReadersExport(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              views:
                - selector:
                    instrument_name: view.multiple.readers
                  stream:
                    name: view.multiple.readers.selected
                    description: View transformed metric
                    attribute_keys:
                      included:
                        - http.method

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('view.multiple.readers')
                    ->add(
                        7,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/users',
                        ],
                    );

            },
        );

        // There should be one export from each reader.
        self::assertCount(2, $this->metrics);

        foreach ($this->metrics as $payload) {
            self::assertCount(
                1,
                $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.multiple.readers.selected")]',
                ),
            );

            self::assertSame(
                ['View transformed metric'],
                $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.multiple.readers.selected")].description',
                ),
            );

            self::assertSame(
                ['http.method'],
                $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.multiple.readers.selected")].sum.dataPoints[*].attributes[*].key',
                ),
            );

            self::assertSame(
                ['7'],
                $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.multiple.readers.selected")].sum.dataPoints[*].asInt',
                ),
            );
        }
    }

    /*
     * =========================================================================
     * Composable views
     * =========================================================================
     */

    public function testComposableViewsWithSameNameProduceOneComposedStream(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.requests
                  stream:
                    name: composable.requests.total
                    description: First description
                    aggregation:
                      sum:

                - selector:
                    instrument_name: composable.requests
                  stream:
                    name: composable.requests.total
                    description: Second description
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter(
                        'composable.requests',
                        '1',
                        'Original description',
                    )
                    ->add(5);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.requests.total")]',
        );

        self::assertCount(1, $metrics);

        // The second View wins for properties other than attribute_keys.
        self::assertSame(
            ['Second description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.requests.total")].description',
            ),
        );

        // The first View selected Sum and the second View did not override it.
        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.requests.total")].sum',
            ),
        );

        self::assertSame(
            '5',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.requests.total")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testComposableViewsUseLastMatchingStreamConfiguration(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.last_wins
                  stream:
                    name: composable.last_wins
                    description: First description
                    aggregation:
                      sum:

                - selector:
                    instrument_name: composable.last_wins
                  stream:
                    name: composable.last_wins
                    description: Second description
                    aggregation:
                      sum:
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('composable.last_wins')
                    ->add(7);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.last_wins")]',
            ),
        );

        self::assertSame(
            ['Second description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.last_wins")].description',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.last_wins")].sum',
            ),
        );
    }

    public function testComposableViewsMergeAttributeKeysUsingIntersection(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.attributes
                  stream:
                    name: composable.attributes
                    attribute_keys:
                      included:
                        - http.method
                        - http.route

                - selector:
                    instrument_name: composable.attributes
                  stream:
                    name: composable.attributes
                    attribute_keys:
                      included:
                        - http.method
                        - http.status_code
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('composable.attributes')
                    ->add(
                        1,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/users',
                            'http.status_code' => '200',
                            'server.address' => 'example.test',
                        ],
                    );

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $keys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.attributes")].sum.dataPoints[*].attributes[*].key',
        );

        // Only http.method is included by both Views.
        self::assertContains('http.method', $keys);
        self::assertNotContains('http.route', $keys);
        self::assertNotContains('http.status_code', $keys);
        self::assertNotContains('server.address', $keys);
    }

    public function testComposableUnnamedViewJoinsNamedStreamGroup(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.named_group
                  stream:
                    name: composable.named
                    aggregation:
                      sum:

                - selector:
                    instrument_name: composable.named_group
                  stream:
                    description: Description from unnamed View
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('composable.named_group')
                    ->add(11);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.named")]',
            ),
        );

        self::assertSame(
            ['Description from unnamed View'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.named")].description',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.named_group")]',
            ),
        );
    }

    public function testComposableViewsWithDifferentNamesProduceSeparateStreams(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.different_names
                  stream:
                    name: composable.first
                    description: First stream

                - selector:
                    instrument_name: composable.different_names
                  stream:
                    name: composable.second
                    description: Second stream
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('composable.different_names')
                    ->add(13);

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.first")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.second")]',
            ),
        );

        self::assertSame(
            ['First stream'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.first")].description',
            ),
        );

        self::assertSame(
            ['Second stream'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.second")].description',
            ),
        );
    }

    public function testComposableViewsApplyMatchingViewsInOrder(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.ordered
                  stream:
                    name: composable.ordered
                    aggregation:
                      sum:

                - selector:
                    instrument_name: composable.ordered
                  stream:
                    attribute_keys:
                      included:
                        - http.method

                - selector:
                    instrument_name: composable.ordered
                  stream:
                    aggregation:
                      last_value:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $gauge = $meter->createGauge('composable.ordered');

                $gauge->record(
                    10,
                    [
                        'http.method' => 'GET',
                        'http.route' => '/users',
                    ],
                );

                $gauge->record(
                    20,
                    [
                        'http.method' => 'POST',
                        'http.route' => '/users',
                    ],
                );

                Globals::meterProvider()->forceFlush();
            },
        );

        $payload = $this->metrics[0];

        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")]',
        );

        self::assertCount(1, $metrics);

        // The third View overrides the aggregation selected by the first View.
        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].gauge',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].sum',
            ),
        );

        // The second View's attribute configuration is retained.
        $keys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].gauge.dataPoints[*].attributes[*].key',
        );

        self::assertContains('http.method', $keys);
        self::assertNotContains('http.route', $keys);

        // Last Value retains the most recently recorded value for each
        // remaining attribute set.
        $methods = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].gauge.dataPoints[*].attributes[?(@.key == "http.method")].value.stringValue',
        );

        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);

        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].gauge.dataPoints[*].asInt',
        );

        self::assertContains('10', $values);
        self::assertContains('20', $values);
    }

    /*
     * =========================================================================
     * Batch LogRecord Processor
     * =========================================================================
     */

    public function testBatchLogRecordProcessorMaxExportBatchSize(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    schedule_delay: 60000
                    max_queue_size: 10
                    max_export_batch_size: 2
                    export_timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('config-test');

                for ($i = 1; $i <= 5; ++$i) {
                    $logger->emit(new LogRecord("log-{$i}"));
                }

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertCount(3, $this->logs);

        self::assertCount(
            2,
            $this->logsInExport($this->logs[0]),
        );

        self::assertCount(
            2,
            $this->logsInExport($this->logs[1]),
        );

        self::assertCount(
            1,
            $this->logsInExport($this->logs[2]),
        );
    }

    public function testBatchLogRecordProcessorScheduleDelay(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    schedule_delay: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('scheduled-log'));

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertNotEmpty($this->logs);

        self::assertSame(
            'scheduled-log',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            )[0],
        );
    }

    /*
     * =========================================================================
     * LogRecord limits
     * =========================================================================
     */

    public function testLogRecordLimits(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              limits:
                attribute_count_limit: 2
                attribute_value_length_limit: 4

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $record = new LogRecord('limited-log');

                $record->setAttributes([
                    'a1' => '123456789',
                    'a2' => '123456789',
                    'a3' => '123456789',
                ]);

                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit($record);

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertCount(
            2,
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].attributes[*]',
            ),
        );

        self::assertSame(
            '1234',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].attributes[?(@.key == "a1")].value.stringValue',
            )[0],
        );
    }

    /*
     * =========================================================================
     * Logger configurator
     * =========================================================================
     */

    public function testLoggerConfiguratorCanDisableDefaultLoggersAndEnableMatchingLogger(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: enabled.logger
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('disabled.logger')
                    ->emit(new LogRecord('disabled'));

                Globals::loggerProvider()
                    ->getLogger('enabled.logger')
                    ->emit(new LogRecord('enabled'));

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['enabled'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorSupportsWildcardMatching(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: application.*
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('application.http')
                    ->emit(new LogRecord('enabled'));

                Globals::loggerProvider()
                    ->getLogger('library.http')
                    ->emit(new LogRecord('disabled'));

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['enabled'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorCanDisableMatchingLoggerWithDefaultEnabled(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: true
                loggers:
                  - name: noisy.logger
                    config:
                      enabled: false

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('normal.logger')
                    ->emit(new LogRecord('normal'));

                Globals::loggerProvider()
                    ->getLogger('noisy.logger')
                    ->emit(new LogRecord('noisy'));

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['normal'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorMinimumSeverity(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: true
                loggers:
                  - name: application.logger
                    config:
                      minimum_severity: warn

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('application.logger');

                $logger->emit(
                    (new LogRecord('debug'))
                        ->setSeverityNumber(5),
                );

                $logger->emit(
                    (new LogRecord('info'))
                        ->setSeverityNumber(9),
                );

                $logger->emit(
                    (new LogRecord('warning'))
                        ->setSeverityNumber(13),
                );

                $logger->emit(
                    (new LogRecord('error'))
                        ->setSeverityNumber(17),
                );

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['warning', 'error'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorMinimumSeverityFiltersBelowThreshold(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: severity-test.logger
                    config:
                      minimum_severity: warn

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('severity-test.logger');

                $logger->emit(
                    (new LogRecord('severity.info'))
                        ->setSeverityNumber(9)
                        ->setSeverityText('INFO'),
                );

                $logger->emit(
                    (new LogRecord('severity.warn'))
                        ->setSeverityNumber(13)
                        ->setSeverityText('WARN'),
                );

                $logger->emit(
                    (new LogRecord('severity.error'))
                        ->setSeverityNumber(17)
                        ->setSeverityText('ERROR'),
                );

                // Severity 0 means unspecified and bypasses minimum severity filtering.
                $logger->emit(
                    new LogRecord('severity.unspecified'),
                );

                Globals::loggerProvider()->forceFlush();
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertNotContains('severity.info', $bodies);
        self::assertContains('severity.warn', $bodies);
        self::assertContains('severity.error', $bodies);
        self::assertContains('severity.unspecified', $bodies);
    }

    public function testLoggerConfiguratorTraceBasedFalseDoesNotFilterUnsampledTraces(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: trace-disabled.logger
                    config:
                      trace_based: false

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('trace-disabled.logger');

                $span = \OpenTelemetry\API\Trace\Span::wrap(
                    SpanContext::create(
                        str_repeat('a', 32),
                        str_repeat('b', 16),
                    ),
                );

                $context = $span->storeInContext(
                    \OpenTelemetry\Context\Context::getCurrent(),
                );

                $logger->emit(
                    (new LogRecord('trace-disabled.unsampled'))
                        ->setContext($context),
                );

                $logger->emit(
                    new LogRecord('trace-disabled.no-context'),
                );

                Globals::loggerProvider()->forceFlush();
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertContains('trace-disabled.unsampled', $bodies);
        self::assertContains('trace-disabled.no-context', $bodies);
    }

    public function testLoggerConfiguratorQuestionMarkWildcard(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: app.?
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $loggerProvider = Globals::loggerProvider();

                $loggerProvider
                    ->getLogger('app.a')
                    ->emit(new LogRecord('enabled'));

                $loggerProvider
                    ->getLogger('app.ab')
                    ->emit(new LogRecord('disabled'));

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['enabled'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorMatchingIsCaseSensitive(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: app.logger
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $loggerProvider = Globals::loggerProvider();

                $loggerProvider
                    ->getLogger('app.logger')
                    ->emit(new LogRecord('lowercase'));

                $loggerProvider
                    ->getLogger('APP.LOGGER')
                    ->emit(new LogRecord('uppercase'));

                Globals::loggerProvider()->forceFlush();
            },
        );

        self::assertSame(
            ['lowercase'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorTraceBasedDropsLogsFromUnsampledTraces(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: trace-based.logger
                    config:
                      trace_based: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('trace-based.logger');

                $unsampledSpan = \OpenTelemetry\API\Trace\Span::wrap(
                    SpanContext::create(
                        str_repeat('a', 32),
                        str_repeat('b', 16),
                    ),
                );

                $scope = $unsampledSpan->activate();

                $logger->emit(new LogRecord('unsampled'));

                $scope->detach();

                $sampledSpan = \OpenTelemetry\API\Trace\Span::wrap(
                    SpanContext::create(
                        str_repeat('c', 32),
                        str_repeat('d', 16),
                        TraceFlags::SAMPLED,
                    ),
                );

                $scope = $sampledSpan->activate();

                $logger->emit(new LogRecord('sampled'));

                $scope->detach();

                // No trace context.
                $logger->emit(new LogRecord('no-context'));

                Globals::loggerProvider()->forceFlush();
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertSame(
            ['sampled', 'no-context'],
            $bodies,
        );

        self::assertNotContains('unsampled', $bodies);
    }

    public function testLoggerConfiguratorTraceBasedFiltersUnsampledTraceRecords(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: trace-based.logger
                    config:
                      trace_based: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('trace-based.logger');

                $unsampledContext = SpanContext::create(
                    str_repeat('a', 32),
                    str_repeat('b', 16),
                    \OpenTelemetry\API\Trace\TraceFlags::DEFAULT,
                );

                $sampledContext = SpanContext::create(
                    str_repeat('c', 32),
                    str_repeat('d', 16),
                    \OpenTelemetry\API\Trace\TraceFlags::SAMPLED,
                );

                $logger->emit(
                    (new LogRecord('unsampled'))
                        ->setContext(Span::wrap($unsampledContext)->storeInContext(Context::getCurrent())),
                );

                $logger->emit(
                    (new LogRecord('sampled'))
                        ->setContext(Span::wrap($sampledContext)->storeInContext(Context::getCurrent())),
                );

                $logger->emit(
                    new LogRecord('no-context'),
                );

                Globals::loggerProvider()->forceFlush();
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertContains('sampled', $bodies);
        self::assertContains('no-context', $bodies);
        self::assertNotContains('unsampled', $bodies);
    }

}

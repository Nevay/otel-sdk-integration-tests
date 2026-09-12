<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use JsonPath\JsonObject;
use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

final class ConfigFileTest extends TestCase {
    use OTelEndpointTrait;

    public function testConfigFileGeneratesTelemetryData(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            log_level: error
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
            static function(): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                $meter = Globals::meterProvider()->getMeter('test');
                $logger = Globals::loggerProvider()->getLogger('test');

                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
                $meter
                    ->createCounter('c')
                    ->add(1);
                $logger
                    ->logRecordBuilder()
                    ->emit();
            }
        );

        $this->assertNotEmpty($this->traces);
        $this->assertNotEmpty($this->metrics);
        $this->assertNotEmpty($this->logs);
    }

    public function testSdkCanBeDisabled(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            log_level: error
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
            static function(): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                $meter = Globals::meterProvider()->getMeter('test');
                $logger = Globals::loggerProvider()->getLogger('test');

                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
                $meter
                    ->createCounter('c')
                    ->add(1);
                $logger
                    ->logRecordBuilder()
                    ->emit();
            }
        );

        $this->assertEmpty($this->traces);
        $this->assertEmpty($this->metrics);
        $this->assertEmpty($this->logs);
    }

    #[Group('configurator')]
    public function testConfiguratorsCanDisableInstrumentationScopes(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            log_level: error
            tracer_provider: 
              tracer_configurator/development: 
                tracers:
                  - name: disabled
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
                  - name: disabled
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
                  - name: disabled
                    config: 
                      enabled: false
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function(): void {
                $tracer = Globals::tracerProvider()->getTracer('disabled');
                $meter = Globals::meterProvider()->getMeter('disabled');
                $logger = Globals::loggerProvider()->getLogger('disabled');

                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
                $meter
                    ->createCounter('c')
                    ->add(1);
                $logger
                    ->logRecordBuilder()
                    ->emit();

                $tracer = Globals::tracerProvider()->getTracer('test');
                $meter = Globals::meterProvider()->getMeter('test');
                $logger = Globals::loggerProvider()->getLogger('test');

                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
                $meter
                    ->createCounter('c')
                    ->add(1);
                $logger
                    ->logRecordBuilder()
                    ->emit();
            }
        );

        $this->assertNotEmpty($this->traces);
        $this->assertNotEmpty($this->metrics);
        $this->assertNotEmpty($this->logs);

        $traces = new JsonObject($this->traces[0]);
        $metrics = new JsonObject($this->metrics[0]);
        $logs = new JsonObject($this->logs[0]);

        $this->assertNotEmpty($traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'test')].spans"));
        $this->assertEmpty($traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'disabled')].spans"));
        $this->assertNotEmpty($metrics->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics"));
        $this->assertEmpty($metrics->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'disabled')].metrics"));
        $this->assertNotEmpty($logs->get(/* @lang JSONPath */"$.resourceLogs[*].scopeLogs[?(@.scope.name == 'test')].logRecords"));
        $this->assertEmpty($logs->get(/* @lang JSONPath */"$.resourceLogs[*].scopeLogs[?(@.scope.name == 'disabled')].logRecords"));
    }

    #[Group('resource')]
    public function testResourceDetectorAttributesCanFilterAttributesExcluded(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            log_level: error
            resource: 
              detection/development: 
                attributes: 
                  excluded:
                    - process.pid
                detectors:
                - process:
            tracer_provider: 
              processors:
                - batch: 
                    exporter: 
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function(): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertNotContains(
            'process.pid',
            $traces->get(/* @lang JSONPath */"$.resourceSpans[*].resource.attributes[*].key"),
        );
        $this->assertContains(
            'process.runtime.name',
            $traces->get(/* @lang JSONPath */"$.resourceSpans[*].resource.attributes[*].key"),
        );
    }

    #[Group('resource')]
    public function testResourceDetectorAttributesCanFilterAttributesIncluded(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            log_level: error
            resource: 
              detection/development: 
                attributes: 
                  included:
                    - process.runtime.*
                detectors:
                - process:
            tracer_provider: 
              processors:
                - batch: 
                    exporter: 
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function(): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertNotContains(
            'process.pid',
            $traces->get(/* @lang JSONPath */"$.resourceSpans[*].resource.attributes[*].key"),
        );
        $this->assertContains(
            'process.runtime.name',
            $traces->get(/* @lang JSONPath */"$.resourceSpans[*].resource.attributes[*].key"),
        );
    }

    #[Group('view')]
    public function testViewsCanFilterAttributes(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
              views:
                - selector:
                    instrument_name: filtered
                  stream:
                    attribute_keys:
                      included:
                        - bar.*
                        - baz
                      excluded:
                        - bar.foo
                - selector:
                    instrument_name: unfiltered
                  stream: {}
            YAML,
            static function(): void {
                $meter = Globals::meterProvider()->getMeter('test');

                $meter
                    ->createCounter('filtered')
                    ->add(1, ['foo' => 1, 'bar.abc' => 2, 'baz' => 3, 'bar.foo' => 4]);
                $meter
                    ->createCounter('unfiltered')
                    ->add(1, ['foo' => 1, 'bar.abc' => 2, 'baz' => 3, 'bar.foo' => 4]);
            }
        );

        $this->assertNotEmpty($this->metrics);
        $metrics = new JsonObject($this->metrics[0]);

        $this->assertSame(
            ['bar.abc', 'baz'],
            $metrics->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'filtered')].sum.dataPoints[0].attributes[*].key")
        );
        $this->assertSame(
            ['foo', 'bar.abc', 'baz', 'bar.foo'],
            $metrics->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'unfiltered')].sum.dataPoints[0].attributes[*].key")
        );
    }

    #[Group('view'), Group('aggregation')]
    public function testViewsCanSpecifyBucketBoundaries(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
              views:
                - selector:
                    instrument_name: h
                  stream:
                    aggregation:
                      explicit_bucket_histogram:
                        boundaries: [0, 5, 10]
            YAML,
            static function(): void {
                $meter = Globals::meterProvider()->getMeter('test');
                $histogram = $meter->createHistogram('h');

                $histogram->record(0);
                $histogram->record(1);
                $histogram->record(2);
                $histogram->record(5);
                $histogram->record(7);
                $histogram->record(15);
            }
        );

        $this->assertNotEmpty($this->metrics);
        $metrics = new JsonObject($this->metrics[0]);

        $this->assertSame(
            [[0, 5, 10]],
            $metrics->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'h')].histogram.dataPoints[0].explicitBounds")
        );
        $this->assertSame(
            [['1', '3', '1', '1']],
            $metrics->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'h')].histogram.dataPoints[0].bucketCounts")
        );
    }

    #[Group('view'), Group('aggregation')]
    public function testViewCanDropMetric(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
              views:
                - selector:
                    instrument_name: h
                  stream:
                    aggregation:
                      drop:
            YAML,
            static function(): void {
                $meter = Globals::meterProvider()->getMeter('test');
                $histogram = $meter->createHistogram('h');

                $histogram->record(5);
            }
        );

        $metrics = new JsonObject($this->metrics[0] ?? []);

        $this->assertEmpty($metrics->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'h')].histogram.dataPoints"));
    }

    #[Group('view'), Group('aggregation')]
    public function testViewDoesNotApplyIncompatibleAggregation(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
              views:
                - selector:
                    instrument_name: gauge
                  stream:
                    aggregation:
                      sum:
            YAML,
            static function(): void {
                $meter = Globals::meterProvider()->getMeter('test');
                $gauge = $meter->createGauge('gauge');

                $gauge->record(5);
                $gauge->record(7);
            }
        );

        $this->assertNotEmpty($this->metrics);
        $metrics = new JsonObject($this->metrics[0]);

        $this->assertSame(
            ['7'],
            $metrics->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'gauge')].gauge.dataPoints[0].asInt")
        );
    }

    #[Group('async'), Group('temporality')]
    public function testCumulativeTemporality(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        temporality_preference: cumulative
                    interval: 100
            YAML,
            static function(): void {
                $meterProvider = Globals::meterProvider();

                $meter = $meterProvider->getMeter('test');
                $counter = $meter->createCounter('c');

                $counter->add(5);
                delay(.15);
                $counter->add(7);
            }
        );

        $this->assertCount(2, $this->metrics);
        $metrics0 = new JsonObject($this->metrics[0]);
        $metrics1 = new JsonObject($this->metrics[1]);

        $this->assertSame(
            ['5'],
            $metrics0->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'c')].sum.dataPoints[0].asInt")
        );
        $this->assertSame(
            ['12'],
            $metrics1->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'c')].sum.dataPoints[0].asInt")
        );
    }

    #[Group('async')]
    public function testDeltaTemporality(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        temporality_preference: delta
                    interval: 100
            YAML,
            static function(): void {
                $meterProvider = Globals::meterProvider();

                $meter = $meterProvider->getMeter('test');
                $counter = $meter->createCounter('c');

                $counter->add(5);
                delay(.15);
                $counter->add(7);
            }
        );

        $this->assertCount(2, $this->metrics);
        $metrics0 = new JsonObject($this->metrics[0]);
        $metrics1 = new JsonObject($this->metrics[1]);

        $this->assertSame(
            ['5'],
            $metrics0->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'c')].sum.dataPoints[0].asInt")
        );
        $this->assertSame(
            ['7'],
            $metrics1->get(/* @lang JSONPath */"$.resourceMetrics[*].scopeMetrics[?(@.scope.name == 'test')].metrics[?(@.name == 'c')].sum.dataPoints[0].asInt")
        );
    }
}
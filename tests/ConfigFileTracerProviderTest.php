<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use JsonPath\JsonObject;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Retry;
use PHPUnit\Framework\TestCase;

final class ConfigFileTracerProviderTest extends TestCase {
    use OTelEndpointTrait;

    #[Group('configurator')]
    public function testTracerConfiguratorTracerCanBeDisabled(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
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
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('disabled');
                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();

                $tracer = Globals::tracerProvider()->getTracer('enabled');
                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertNotEmpty($traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'enabled')].spans"));
        $this->assertEmpty($traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'disabled')].spans"));
    }

    #[Group('configurator')]
    public function testTracerConfiguratorTracerCanBeEnabled(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            tracer_provider: 
              tracer_configurator/development: 
                default_config: 
                  enabled: false
                tracers:
                  - name: enabled
                    config: 
                      enabled: true
              processors:
                - batch: 
                    exporter: 
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('disabled');
                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();

                $tracer = Globals::tracerProvider()->getTracer('enabled');
                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertNotEmpty($traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'enabled')].spans"));
        $this->assertEmpty($traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'disabled')].spans"));
    }

    #[Group('sampler')]
    public function testSamplerAlwaysOffSamplesNoSpans(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
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
                $tracer = Globals::tracerProvider()->getTracer('tracer');
                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
            }
        );

        $this->assertEmpty($this->traces);
    }

    #[Group('sampler'), Retry(5)]
    public function testSamplerTraceIdRatioSamplesRatio(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            tracer_provider: 
              sampler:
                trace_id_ratio_based:
                  ratio: 0.5
              processors:
                - batch: 
                    exporter: 
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                for ($i = 0; $i < 100; $i++) {
                    $tracer
                        ->spanBuilder('test')
                        ->startSpan()
                        ->end();
                }
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertNotCount(0, $traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'test')].spans"));
        $this->assertNotCount(100, $traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'test')].spans"));
    }

    #[Group('limits')]
    public function testLinkCountLimit(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            tracer_provider: 
              limits: 
                link_count_limit: 2
              processors:
                - batch: 
                    exporter: 
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                $tracer
                    ->spanBuilder('test')
                    ->addLink(SpanContext::createFromRemoteParent('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331'))
                    ->addLink(SpanContext::createFromRemoteParent('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331'))
                    ->addLink(SpanContext::createFromRemoteParent('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331'))
                    ->startSpan()
                    ->end();
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertCount(2, $traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'test')].spans[0].links[*]"));
    }

    #[Group('limits')]
    public function testEventCountLimit(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            tracer_provider: 
              limits: 
                event_count_limit: 2
              processors:
                - batch: 
                    exporter: 
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->addEvent('event')
                    ->addEvent('event')
                    ->addEvent('event')
                    ->end();
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertCount(2, $traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'test')].spans[0].events[*]"));
    }

    #[Group('limits')]
    public function testAttributeCountLimit(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            tracer_provider: 
              limits: 
                attribute_count_limit: 2
              processors:
                - batch: 
                    exporter: 
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->setAttribute('key-1', 'value-1')
                    ->setAttribute('key-2', 'value-2')
                    ->setAttribute('key-3', 'value-3')
                    ->end();
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertCount(2, $traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'test')].spans[0].attributes[*]"));
    }

    #[Group('limits')]
    public function testAttributeValueLengthLimit(): void {
        $this->runOTelConfig(
            /* @lang yaml */ <<<'YAML'
            # $schema: https://raw.githubusercontent.com/open-telemetry/opentelemetry-configuration/refs/tags/v1.1.0/opentelemetry_configuration.json
            file_format: '1.1'
            tracer_provider: 
              limits: 
                attribute_value_length_limit: 5
              processors:
                - batch: 
                    exporter: 
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->setAttribute('key-1', 'value-1')
                    ->setAttribute('key-2', 'value-2')
                    ->setAttribute('key-3', 'value-3')
                    ->end();
            }
        );

        $this->assertNotEmpty($this->traces);
        $traces = new JsonObject($this->traces[0]);

        $this->assertSame(
            ['value', 'value', 'value'],
            $traces->get(/* @lang JSONPath */"$.resourceSpans[*].scopeSpans[?(@.scope.name == 'test')].spans[0].attributes[*].value.stringValue"),
        );
    }
}
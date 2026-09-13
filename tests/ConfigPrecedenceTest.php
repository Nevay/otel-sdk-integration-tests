<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\TestCase;

final class ConfigPrecedenceTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Configuration file vs environment variables
     * =========================================================================
     */

    public function testConfigFileResourceAttributesTakePrecedenceOverEnvironment(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: from-config-file
                - name: custom.attribute
                  value: config-value

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('precedence')
                    ->startSpan()
                    ->end();
            },
            'OTEL_SERVICE_NAME=from-env',
            'OTEL_RESOURCE_ATTRIBUTES=custom.attribute=env-value,other.attribute=env-only',
        );

        self::assertSame(
            'from-config-file',
            $this->resourceAttribute($this->traces[0], 'service.name'),
        );

        self::assertSame(
            'config-value',
            $this->resourceAttribute($this->traces[0], 'custom.attribute'),
        );

        /*
         * Resource attributes that only exist in the environment are not
         * merged in: the config file replaces the environment resource.
         */
        self::assertSame(
            [],
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].resource.attributes[?(@.key == "other.attribute")]',
            ),
        );
    }

    public function testConfigFileIgnoresSdkDisabledEnvVar(): void
    {
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
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('not-disabled')
                    ->startSpan()
                    ->end();
            },
            'OTEL_SDK_DISABLED=true',
        );

        self::assertSame(
            ['not-disabled'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testConfigFileIgnoresTracesSamplerEnvVar(): void
    {
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
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('sampled-anyway')
                    ->startSpan()
                    ->end();
            },
            'OTEL_TRACES_SAMPLER=always_off',
        );

        self::assertSame(
            ['sampled-anyway'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testConfigModeDefaultPropagatorIsNone(): void
    {
        /*
         * In contrast to environment-based configuration, where the default
         * propagator injects a traceparent header, a config file without an
         * explicit propagator section results in no injection at all.
         */
        $output = $this->runOTelConfig(
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
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $carrier = [];

                Globals::propagator()->inject(
                    $carrier,
                    null,
                    $context,
                );

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
        );

        self::assertSame(
            [],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }
}

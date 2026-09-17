<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('config-file'), Group('traces')]
final class ConfigTracerConfiguratorTest extends TestCase {
    use OTelEndpointTrait;

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
            },
        );

        self::assertSame(
            ['lowercase'],
            $this->spanNames($this->traces[0]),
        );
    }
}

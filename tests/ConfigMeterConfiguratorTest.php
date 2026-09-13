<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

    #[Group('config-file'), Group('metrics')]
final class ConfigMeterConfiguratorTest extends TestCase {
    use OTelEndpointTrait;

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
}

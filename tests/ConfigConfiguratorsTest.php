<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('config-file')]
final class ConfigConfiguratorsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Configurators
     * =========================================================================
     */

    #[Group('traces'), Group('metrics')]
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

    #[Group('traces'), Group('metrics'), Group('logs')]
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

    #[Group('traces'), Group('metrics'), Group('logs')]
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
}

<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

    #[Group('config-file')]
final class ConfigBasicTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Basic configuration
     * =========================================================================
     */

    #[Group('traces')]
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

    #[Group('traces')]
    public function testOtlpHttpExporterHeadersAreSentToCollector(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        headers:
                          - name: auth
                            value: config-token
                          - name: x-custom
                            value: v2
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('config-headers')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The headers configured on the otlp_http exporter are sent with
         * the export request.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);

        self::assertSame(['config-token'], $headers['auth']);
        self::assertSame(['v2'], $headers['x-custom']);
    }

    #[Group('traces')]
    public function testOtlpHttpGzipCompressionIsApplied(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        compression: gzip
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('gzip-span')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The request body is gzip-compressed and marked as such; the fake
         * collector decodes it before parsing.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['gzip'], $headers['content-encoding']);

        self::assertContains('gzip-span', $this->spanNames($this->traces[0]));
    }

    #[Group('traces'), Group('metrics'), Group('logs')]
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
     * SDK disabled
     * =========================================================================
     */

    #[Group('traces'), Group('metrics'), Group('logs')]
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
            },
        );

        self::assertSame([], $this->traces);
        self::assertSame([], $this->metrics);
        self::assertSame([], $this->logs);
    }
}

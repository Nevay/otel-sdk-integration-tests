<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('config-file')]
final class ConfigSelfObservabilityTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * SDK self-observability (semconv: /docs/specs/semconv/otel/)
     * =========================================================================
     */

    /**
     * The metric names defined by the semantic conventions for OpenTelemetry
     * SDK metrics (development status).
     *
     * @var list<string>
     */
    private const SEMCONV_METRICS = [
        'otel.sdk.span.live',
        'otel.sdk.span.started',
        'otel.sdk.processor.span.queue.size',
        'otel.sdk.processor.span.queue.capacity',
        'otel.sdk.processor.span.processed',
        'otel.sdk.exporter.span.inflight',
        'otel.sdk.exporter.span.exported',
        'otel.sdk.log.created',
        'otel.sdk.processor.log.queue.size',
        'otel.sdk.processor.log.queue.capacity',
        'otel.sdk.processor.log.processed',
        'otel.sdk.exporter.log.inflight',
        'otel.sdk.exporter.log.exported',
        'otel.sdk.exporter.metric_data_point.inflight',
        'otel.sdk.exporter.metric_data_point.exported',
        'otel.sdk.metric_reader.collection.duration',
        'otel.sdk.exporter.operation.duration',
    ];

    #[Group('async')]
    public function testSelfObservabilityIsDisabledByDefault(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    schedule_delay: 50
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

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
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('self-obs-off')
                    ->startSpan();
                $span->end();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.counter', '{x}')
                    ->add(1);

                /*
                 * Stay alive across several export cycles so that any
                 * self-observability instrumentation would have had the
                 * chance to be recorded and exported.
                 */
                \Amp\delay(0.5);
            },
        );

        /*
         * SDK self-observability is filtered out by default: none of the
         * semconv-defined otel.sdk.* metrics may reach the collector.
         */
        foreach ($this->metrics as $payload) {
            self::assertSame(
                [],
                $this->path($payload, '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name =~ /^otel\.sdk\./)].name'),
            );
        }
    }

    #[Group('tbachert'), Group('metrics')]
    public function testSelfObservabilityMetricsCanBeEnabled(): void
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

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
              meter_configurator/development:
                meters:
                  - name: com.tobiasbachert.otel.sdk.otlpexporter
                    config:
                      enabled: true
            YAML,
            static function (): void {
                /*
                 * Self-observability instruments are only recorded while the
                 * SDK is actually exporting telemetry: ending this span
                 * triggers the exporter, which records its instruments
                 * against the SDK's own meter provider.
                 */
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('self-obs-metrics')
                    ->startSpan();
                $span->end();
            },
        );

        self::assertCount(1, $this->metrics);
        $payload = $this->metrics[0];

        /*
         * Enabling the exporter's instrumentation scope via the development
         * configurator exports its self-observability instruments. Only the
         * span was emitted, so only span-exporter metrics may appear — and
         * every exported name must be one of the semconv-defined ones.
         */
        $metrics = array_filter(
            $this->path($payload, '$.resourceMetrics[*].scopeMetrics[*].metrics[*]'),
            static fn(array $metric): bool => str_starts_with($metric['name'] ?? '', 'otel.sdk.'),
        );
        self::assertNotEmpty($metrics);

        $names = array_map(static fn(array $metric): string => $metric['name'], $metrics);
        foreach ($names as $name) {
            self::assertContains($name, self::SEMCONV_METRICS);
        }

        /*
         * The shutdown collection happens while the final span export is in
         * flight, so the semconv-defined otel.sdk.exporter.span.inflight
         * metric (an UpDownCounter of {span}) must be present: a cumulative,
         * non-monotonic sum carrying the recommended component attributes.
         */
        self::assertContains('otel.sdk.exporter.span.inflight', $names);

        $inflight = null;
        foreach ($metrics as $metric) {
            if ($metric['name'] === 'otel.sdk.exporter.span.inflight') {
                $inflight = $metric;
            }
        }
        self::assertNotNull($inflight);
        self::assertSame('{span}', $inflight['unit']);
        self::assertSame(2, $inflight['sum']['aggregationTemporality']);
        self::assertFalse($inflight['sum']['isMonotonic'] ?? false);

        $attributes = array_column($inflight['sum']['dataPoints'][0]['attributes'], 'key');
        self::assertContains('otel.component.name', $attributes);
        self::assertContains('otel.component.type', $attributes);
    }
}

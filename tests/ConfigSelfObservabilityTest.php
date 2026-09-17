<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('config-file')]
final class ConfigSelfObservabilityTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * SDK self-observability (semconv: /docs/specs/semconv/otel/)
     * =========================================================================
     */

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
}

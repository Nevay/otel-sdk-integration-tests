<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use OpenTelemetry\API\Globals;
use Throwable;
use PHPUnit\Framework\TestCase;

final class EnvMetricsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Metrics
     * =========================================================================
     */

    public function testPrometheusExporterServesMetricsOnConfiguredPort(): void {
        $port = 39464;

        $exposition = $this->runOTel(
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter('test.counter', 'requests', 'a probe counter')
                    ->add(42);

                /*
                 * The Prometheus exporter runs its own HTTP server inside
                 * this process, so scrape it from here with the async client.
                 */
                $client = HttpClientBuilder::buildDefault();
                $body = '';
                for ($i = 0; $i < 50 && $body === ''; $i++) {
                    try {
                        $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                        $request->setHeader('accept', 'text/plain;version=0.0.4');
                        $response = $client->request($request);
                        if ($response->getStatus() === 200) {
                            $body = (string) $response->getBody();
                        }
                    } catch (Throwable) {
                        // The server may not be listening yet.
                    }
                    \Amp\delay(0.1);
                }

                echo $body;
            },
            'OTEL_METRICS_EXPORTER=prometheus',
            'OTEL_EXPORTER_PROMETHEUS_HOST=127.0.0.1',
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * The counter is exposed in the Prometheus text format: dots are
         * translated to underscores, the unit is inserted before the _total
         * suffix, and the meter name becomes a scope label.
         */
        self::assertStringContainsString('# TYPE test_counter_requests_total counter', $exposition);
        self::assertStringContainsString('# HELP test_counter_requests_total a probe counter', $exposition);
        self::assertStringContainsString('test_counter_requests_total{otel_scope_name="prom-test"} 42', $exposition);

        /*
         * Resource attributes are exposed as the target_info metric.
         */
        self::assertMatchesRegularExpression('/^target_info\{.*\} 1$/m', $exposition);

        /*
         * Metrics no longer go through the OTLP metrics endpoint.
         */
        self::assertSame([], $this->metrics);
    }

    public function testMetricExportInterval(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('test.counter')
                    ->add(1);
            },
            'OTEL_METRIC_EXPORT_INTERVAL=100',
        );

        self::assertNotEmpty($this->metrics);

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "test.counter")]',
            ),
        );
    }

    public function testMetricExportTimeout(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('timeout.counter')
                    ->add(1);
            },
            'OTEL_METRIC_EXPORT_TIMEOUT=100',
        );

        self::assertIsArray($this->metrics);
    }

    public function testMetricsExemplarFilterAlwaysOff(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $span = $tracer
                    ->spanBuilder('exemplar')
                    ->startSpan();

                $scope = $span->activate();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('exemplar.counter')
                    ->add(1);

                $scope->detach();
                $span->end();
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=always_off',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->metrics);

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[*]..exemplars[*]',
            ),
        );
    }

    public function testMetricsExemplarFilterAlwaysOnCapturesWithoutSpan(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('no-span.counter')
                    ->add(1);
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=always_on',
        );

        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "no-span.counter")]..exemplars[*]',
        );

        /*
         * always_on captures measurements even without an active span;
         * the exemplar then carries no trace context.
         */
        self::assertCount(1, $exemplars);
        self::assertSame('1', $exemplars[0]['asInt']);
        self::assertArrayNotHasKey('traceId', $exemplars[0]);
        self::assertArrayNotHasKey('spanId', $exemplars[0]);
    }

    public function testMetricsExemplarFilterTraceBasedOnlyCapturesInSampledSpans(): void {
        $this->runOTel(
            static function (): void {
                // Measurement without an active span: no exemplar.
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('outside.counter')
                    ->add(1);

                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('tb-parent')
                    ->startSpan();

                $scope = $span->activate();

                // Measurement inside a sampled span: exemplar with trace context.
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('inside.counter')
                    ->add(1);

                $scope->detach();
                $span->end();
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=trace_based',
        );

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "outside.counter")]..exemplars[*]',
            ),
        );

        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "inside.counter")]..exemplars[*]',
        );

        self::assertCount(1, $exemplars);
        self::assertSame('1', $exemplars[0]['asInt']);
        self::assertArrayHasKey('traceId', $exemplars[0]);
        self::assertArrayHasKey('spanId', $exemplars[0]);
    }
}

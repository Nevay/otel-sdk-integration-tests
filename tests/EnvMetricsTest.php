<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('env'), Group('metrics')]
final class EnvMetricsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Metrics
     * =========================================================================
     */

    #[Group('prometheus')]
    public function testPrometheusExporterServesMetricsOnConfiguredPort(): void {
        $port = 39464;

        $exposition = $this->runOTel(
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter('test.counter', 'requests', 'a probe counter')
                    ->add(42);

                /*
                 * The Prometheus exporter starts its HTTP server during
                 * SDK initialization, which completes before this closure
                 * runs, so a single scrape is sufficient.
                 */
                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $request->setHeader('accept', 'text/plain;version=0.0.4');
                $response = $client->request($request);

                echo (string) $response->getBody();
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

    #[Group('async')]
    public function testMetricExportTimeoutEnvVarDropsExportWhenCollectorIsSlow(): void {
        /*
         * The retry backoff after a timed-out export would otherwise keep
         * the process alive for tens of seconds; bound the shutdown with the
         * vendor-specific timeout so the test stays fast.
         */
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('timeout.counter')
                    ->add(1);
            },
            'OTEL_METRIC_EXPORT_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=' . str_replace(
                '/v1/metrics',
                '/v1/slow',
                $this->env['OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'],
            ),
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
        );

        /*
         * The export was attempted... the slow route delays its response
         * beyond the 100 ms export timeout...
         */
        self::assertGreaterThanOrEqual(1, $this->slowRequests);

        /*
         * ...so the data point was never delivered.
         */
        self::assertSame([], $this->metrics);
    }

    #[Group('async')]
    public function testMetricsOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow(): void {
        /*
         * The per-signal OTEL_EXPORTER_OTLP_METRICS_TIMEOUT bounds each
         * metrics export attempt in milliseconds (the signal-agnostic
         * variable is covered by EnvEdgeCasesTest). The /v1/slow route
         * answers 500 ms after the request, beyond the 100 ms timeout, so
         * the export is cancelled and the data point dropped.
         *
         * OTEL_PHP_SHUTDOWN_TIMEOUT is a tbachert/otel-sdk vendor variable
         * that bounds that SDK's retry backoff after the timed-out export;
         * open-telemetry/sdk ignores it and completes its shutdown on its
         * own within a second.
         */
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('otlp-timeout.counter')
                    ->add(1);
            },
            'OTEL_EXPORTER_OTLP_METRICS_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=' . $this->baseUrl . '/v1/slow',
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
        );

        self::assertGreaterThanOrEqual(1, $this->slowRequests);
        self::assertSame([], $this->metrics);
    }

    public function testInvalidExemplarFilterFallsBackToTraceBased(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                $span = $tracer
                    ->spanBuilder('exemplar-invalid-filter')
                    ->startSpan();

                $scope = $span->activate();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('invalid-filter.counter')
                    ->add(1);

                $scope->detach();
                $span->end();
            },
            'OTEL_METRICS_EXEMPLAR_FILTER=bogus-value',
        );

        self::assertNotEmpty($this->metrics);

        /*
         * An unknown filter value must not break startup; per the common
         * configuration guidance it is ignored and the default (trace_based)
         * applies: the measurement inside a sampled span becomes an exemplar.
         */
        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "invalid-filter.counter")]..exemplars[*]',
        );

        self::assertCount(1, $exemplars);
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

    #[Group('async')]
    /*
     * OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE is a specification
     * variable (Metrics SDK exporters, OTLP) and both SDKs implement it. The
     * test needs multiple periodic exports to observe delta values, so it
     * also depends on OTEL_METRIC_EXPORT_INTERVAL being applied; the
     * official SDK currently only exports at shutdown, where a single
     * collection cannot distinguish delta from cumulative.
     */
    public function testMetricsTemporalityPreferenceEnvVarUsesDelta(): void
    {
        $this->runOTel(
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('temporality-test')
                    ->createCounter('test.requests', 'requests');

                $counter->add(5);
                \Amp\delay(0.4);
                $counter->add(3);
                \Amp\delay(0.4);
            },
            'OTEL_METRIC_EXPORT_INTERVAL=250',
            'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE=delta',
        );

        self::assertGreaterThanOrEqual(
            2,
            count($this->metrics),
            'Expected multiple periodic metric exports.',
        );

        $exports = [];

        foreach ($this->metrics as $payload) {
            $dataPoints = $this->dataPoints($payload, 'test.requests');

            if ($dataPoints !== []) {
                $exports[] = $dataPoints[0];
            }
        }

        $exports = $this->sortByCollectionTime($exports);

        /*
         * Delta temporality: each collection cycle exports only the values
         * recorded since the previous cycle.
         */
        self::assertSame('5', $exports[0]['asInt']);
        self::assertSame('3', $exports[1]['asInt']);
    }

    public function testDefaultHistogramAggregationEnvVarUsesExponentialBuckets(): void {
        $this->runOTel(
            static function (): void {
                $histogram = Globals::meterProvider()
                    ->getMeter('env-test')
                    ->createHistogram('agg.histogram');

                foreach ([0.5, 1, 2, 4, 8, 16] as $value) {
                    $histogram->record($value);
                }
            },
            'OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION=base2_exponential_bucket_histogram',
        );

        self::assertNotEmpty($this->metrics);

        /*
         * The environment variable switches the default histogram aggregation
         * to base-2 exponential buckets: data points carry a scale and
         * offset/bucket-count pairs instead of explicit bounds.
         */
        $dataPoint = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "agg.histogram")].exponentialHistogram.dataPoints[0]',
        )[0];

        self::assertArrayNotHasKey('explicitBounds', $dataPoint);
        self::assertIsInt($dataPoint['scale']);
        self::assertSame(
            6,
            array_sum(array_map('intval', $dataPoint['positive']['bucketCounts'])),
        );
    }

    /*
     * OTEL_METRIC_EXPORT_INTERVAL (spec) sets the periodic metric reader's
     * collection interval in milliseconds. With a 250 ms interval and two
     * measurement rounds spread over 800 ms, the collector must receive more
     * than one export.
     */
    public function testMetricExportIntervalEnvVarControlsCollectionFrequency(): void
    {
        $this->runOTel(
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('interval.counter');

                $counter->add(5);
                \Amp\delay(0.4);
                $counter->add(3);
                \Amp\delay(0.4);
            },
            'OTEL_METRIC_EXPORT_INTERVAL=250',
        );

        self::assertGreaterThanOrEqual(2, count($this->metrics));
    }
}

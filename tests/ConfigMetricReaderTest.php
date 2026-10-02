<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Opentelemetry\Proto\Metrics\V1\AggregationTemporality;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

#[Group('spec')]
#[Group('config-file'), Group('metrics')]
final class ConfigMetricReaderTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Metric reader & exemplars
     * =========================================================================
     */

    public function testPeriodicMetricReader(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

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
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.counter')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "config.counter")]',
            ),
        );
    }

    public function testMetricsExemplarFilterAlwaysOff(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              exemplar_filter: always_off

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                $span = $tracer
                    ->spanBuilder('exemplar')
                    ->startSpan();

                $scope = $span->activate();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('exemplar.counter')
                    ->add(1);

                $scope->detach();
                $span->end();
            },
        );

        self::assertNotEmpty($this->metrics);

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[*]..exemplars[*]',
            ),
        );
    }

    public function testMetricsExemplarFilterAlwaysOn(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              exemplar_filter: always_on

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('no-span.counter')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

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

    public function testMetricsExemplarFilterTraceBasedCapturesOnlyUnderSampledSpans(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              exemplar_filter: trace_based

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                $span = $tracer->spanBuilder('trace-based')->startSpan();
                $scope = $span->activate();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('in-span.counter')
                    ->add(3);

                $scope->detach();
                $span->end();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('no-span.counter')
                    ->add(4);
            },
        );

        self::assertNotEmpty($this->metrics);

        /*
         * trace_based captures exemplars only while a sampled span is
         * active: the measurement inside the root span carries its trace
         * context, the one outside any span is not captured at all.
         */
        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "in-span.counter")]..exemplars[*]',
        );

        self::assertCount(1, $exemplars);
        self::assertSame('3', $exemplars[0]['asInt']);
        self::assertArrayHasKey('traceId', $exemplars[0]);
        self::assertArrayHasKey('spanId', $exemplars[0]);

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "no-span.counter")]..exemplars[*]',
            ),
        );
    }

    public function testMetricsExemplarFilterDefaultsToTraceBased(): void
    {
        /*
         * Without an exemplar_filter node the trace_based default applies:
         * exemplars are captured only while a sampled span is active.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                $span = $tracer->spanBuilder('default-trace-based')->startSpan();
                $scope = $span->activate();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('in-span.counter')
                    ->add(3);

                $scope->detach();
                $span->end();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('no-span.counter')
                    ->add(4);
            },
        );

        self::assertNotEmpty($this->metrics);

        $exemplars = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "in-span.counter")]..exemplars[*]',
        );

        self::assertCount(1, $exemplars);
        self::assertSame('3', $exemplars[0]['asInt']);
        self::assertArrayHasKey('traceId', $exemplars[0]);
        self::assertArrayHasKey('spanId', $exemplars[0]);

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "no-span.counter")]..exemplars[*]',
            ),
        );
    }






    /*
     * =========================================================================
     * Prometheus escaping schemes (content negotiation)
     * =========================================================================
     */





    #[Group('metrics')]
    public function testExporterDefaultBase2ExponentialHistogramAggregation(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        default_histogram_aggregation: base2_exponential_bucket_histogram
            YAML, static function (): void {
            $histogram = Globals::meterProvider()->getMeter('config-test')
                ->createHistogram('b2.histogram');

            foreach ([0.5, 1, 2, 4, 8, 16] as $value) {
                $histogram->record($value);
            }
        });

        /*
         * The exporter-level default switches the histogram to base-2
         * exponential aggregation: data points carry a scale and an
         * offset/bucket-count pair instead of explicit bounds.
         */
        $dataPoint = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "b2.histogram")].exponentialHistogram.dataPoints[0]',
        )[0];

        self::assertArrayNotHasKey('explicitBounds', $dataPoint);
        self::assertIsInt($dataPoint['scale']);
        self::assertSame(
            6,
            array_sum(array_map('intval', $dataPoint['positive']['bucketCounts'])),
        );
        self::assertEqualsWithDelta(0.5, $dataPoint['min'], 0.001);
        self::assertEqualsWithDelta(16.0, $dataPoint['max'], 0.001);
    }

    #[Group('metrics')]
    #[Group('metrics')]
    public function testBase2ExponentialBucketHistogramDefaultsToSpecMaxScaleAndSize(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        default_histogram_aggregation: base2_exponential_bucket_histogram
            YAML, static function (): void {
            $histogram = Globals::meterProvider()->getMeter('config-test')
                ->createHistogram('b2.default-limits.histogram');

            foreach ([0.5, 1, 2, 4, 8, 16] as $value) {
                $histogram->record($value);
            }
        });

        /*
         * Without max_scale and max_size, the spec defaults (20 and 160)
         * apply: the chosen scale stays within the default bound and the
         * bucket count never exceeds the default size.
         */
        $dataPoint = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "b2.default-limits.histogram")].exponentialHistogram.dataPoints[0]',
        )[0];

        self::assertIsInt($dataPoint['scale']);
        self::assertLessThanOrEqual(20, $dataPoint['scale']);
        self::assertLessThanOrEqual(
            160,
            count($dataPoint['positive']['bucketCounts']),
        );
    }

    /*
     * =========================================================================
     * Temporality
     * =========================================================================
     */

    #[Group('async')]
    public function testMetricsExporterUsesCumulativeTemporality(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 300
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        temporality_preference: cumulative
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'temporality-test',
                    '1.0.0',
                );

                $counter = $meter->createCounter(
                    'test.requests',
                    'requests',
                );

                $counter->add(5);

                delay(0.5);

                $counter->add(3);

                delay(0.5);

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        self::assertGreaterThanOrEqual(
            2,
            count($this->metrics),
            'Expected multiple periodic metric exports.',
        );

        $exports = [];

        foreach ($this->metrics as $payload) {
            $dataPoints = $this->dataPoints(
                $payload,
                'test.requests',
            );

            if ($dataPoints !== []) {
                $exports[] = $dataPoints[0];
            }
        }

        $exports = $this->sortByCollectionTime($exports);

        self::assertGreaterThanOrEqual(
            2,
            count($exports),
            'Expected the metric to be exported in multiple collection cycles.',
        );

        self::assertSame(
            '5',
            $exports[0]['asInt'],
        );

        self::assertSame(
            '8',
            $exports[array_key_last($exports)]['asInt'],
        );

        /*
         * Cumulative counter values must never decrease between exports.
         */
        $previous = null;

        foreach ($exports as $export) {
            $value = (int) $export['asInt'];

            if ($previous !== null) {
                self::assertGreaterThanOrEqual(
                    $previous,
                    $value,
                    'Cumulative counter value decreased between exports.',
                );
            }

            $previous = $value;
        }
    }

    #[Group('async')]
    public function testMetricsExporterUsesDeltaTemporality(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 300
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        temporality_preference: delta
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'temporality-test',
                    '1.0.0',
                );

                $counter = $meter->createCounter(
                    'test.requests',
                    'requests',
                );

                $counter->add(5);

                delay(0.5);

                $counter->add(3);

                delay(0.5);

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        self::assertGreaterThanOrEqual(
            2,
            count($this->metrics),
            'Expected multiple periodic metric exports.',
        );

        $exports = [];

        foreach ($this->metrics as $payload) {
            $dataPoints = $this->dataPoints(
                $payload,
                'test.requests',
            );

            if ($dataPoints !== []) {
                $exports[] = $dataPoints[0];
            }
        }

        $exports = $this->sortByCollectionTime($exports);

        self::assertGreaterThanOrEqual(
            2,
            count($exports),
            'Expected the metric to be exported in multiple collection cycles.',
        );

        self::assertSame(
            '5',
            $exports[0]['asInt'],
        );

        self::assertSame(
            '3',
            $exports[1]['asInt'],
        );

        /*
         * The sum of all delta exports must equal the total recorded,
         * i.e. no data was lost between collection cycles.
         */
        self::assertSame(
            8,
            array_sum(
                array_map(
                    static fn (array $dataPoint): int => (int) $dataPoint['asInt'],
                    $exports,
                ),
            ),
        );
    }

    /*
     * The file-based configuration accepts the values cumulative, delta and
     * low_memory for temporality_preference. low_memory uses delta aggregation
     * temporality for synchronous counter and histogram instruments and
     * cumulative aggregation temporality for asynchronous counters. The
     * temporality is carried on every data point, so the shutdown export is
     * enough to observe it.
     */
    public function testMetricsExporterUsesLowMemoryTemporality(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 300
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        temporality_preference: low_memory
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'low-memory-test',
                    '1.0.0',
                );

                $counter = $meter->createCounter(
                    'test.requests',
                    'requests',
                );

                $counter->add(5);

                $asyncCounter = $meter->createObservableCounter(
                    'test.async.requests',
                    'requests',
                );

                $asyncCounter->observe(
                    static function (ObserverInterface $observer): void {
                        $observer->observe(7);
                    },
                );

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        self::assertNotEmpty(
            $this->metrics,
            'Expected a metrics export at shutdown.',
        );

        $payload = $this->metrics[array_key_last($this->metrics)];

        $sync = $this->metric($payload, 'test.requests');

        self::assertSame(
            AggregationTemporality::AGGREGATION_TEMPORALITY_DELTA,
            $sync['sum']['aggregationTemporality'],
            'The synchronous counter must use delta temporality under low_memory.',
        );

        self::assertTrue($sync['sum']['isMonotonic']);

        $async = $this->metric($payload, 'test.async.requests');

        self::assertSame(
            AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE,
            $async['sum']['aggregationTemporality'],
            'The asynchronous counter must use cumulative temporality under low_memory.',
        );

        self::assertTrue($async['sum']['isMonotonic']);
    }

    #[Group('async')]
    public function testMetricsCumulativeTemporalityPreservesStartTimestamp(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 300
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        temporality_preference: cumulative
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'temporality-test',
                    '1.0.0',
                );

                $counter = $meter->createCounter(
                    'test.requests',
                    'requests',
                );

                $counter->add(5);

                delay(0.5);

                $counter->add(3);

                delay(0.5);

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        $exports = [];

        foreach ($this->metrics as $payload) {
            $points = $this->dataPoints(
                $payload,
                'test.requests',
            );

            if ($points !== []) {
                $exports[] = $points[0];
            }
        }

        $exports = $this->sortByCollectionTime($exports);

        self::assertGreaterThanOrEqual(2, count($exports));

        $first = $exports[0];
        $last = $exports[array_key_last($exports)];

        self::assertSame(
            '5',
            $first['asInt'],
        );

        self::assertSame(
            '8',
            $last['asInt'],
        );

        self::assertArrayHasKey(
            'startTimeUnixNano',
            $first,
        );

        self::assertArrayHasKey(
            'startTimeUnixNano',
            $last,
        );

        self::assertSame(
            $first['startTimeUnixNano'],
            $last['startTimeUnixNano'],
        );

        self::assertLessThan(
            (int) $last['timeUnixNano'],
            (int) $first['startTimeUnixNano'],
        );
    }

    #[Group('aggregation')]
    public function testReaderCardinalityLimitBucketsOverflowSeries(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                    cardinality_limits:
                      default: 2
            YAML, static function (): void {
            $counter = Globals::meterProvider()->getMeter('config-test')
                ->createCounter('cardinality.counter');

            foreach (['a', 'b', 'c', 'd'] as $key) {
                $counter->add(1, ['k' => $key]);
            }
        });

        /*
         * The reader limits the number of attribute sets per instrument:
         * the first two are kept, the rest land in an overflow data point.
         */
        $base = '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "cardinality.counter")].sum';

        self::assertCount(
            3,
            $this->path($this->metrics[0], $base . '.dataPoints[*].asInt'),
        );
        self::assertSame(
            ['k', 'k', 'otel.metric.overflow'],
            $this->path($this->metrics[0], $base . '.dataPoints[*].attributes[*].key'),
        );
        self::assertSame(
            '2',
            (string) $this->path($this->metrics[0], $base . '.dataPoints[2].asInt')[0],
        );
    }

    #[Group('async'), Group('aggregation')]
    public function testReaderCardinalityLimitsApplyPerInstrumentType(): void {
        /*
         * The reader-level cardinality limits apply per instrument type:
         * the counter is limited to one attribute set (plus the overflow
         * aggregation), while the up-down counter keeps its default limit
         * and reports all three of its attribute sets.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
                    cardinality_limits:
                      counter: 1
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('cardinality-test');

                $counter = $meter->createCounter('test.limited.counter', 'requests');
                $counter->add(1, ['request.id' => 'request-1']);
                $counter->add(2, ['request.id' => 'request-2']);

                $upDownCounter = $meter->createUpDownCounter('test.unlimited.updown', 'balance');
                foreach (['a', 'b', 'c'] as $id) {
                    $upDownCounter->add(1, ['bucket' => $id]);
                }

                delay(0.4);

                echo 'done';
            },
        );

        self::assertNotEmpty($this->metrics);

        $payload = $this->metrics[array_key_last($this->metrics)];

        $limited = $this->dataPoints($payload, 'test.limited.counter');
        self::assertCount(
            2,
            $limited,
            'Expected one regular series and one overflow series.',
        );

        $overflow = 0;
        foreach ($limited as $dataPoint) {
            foreach ($dataPoint['attributes'] ?? [] as $attribute) {
                if (
                    $attribute['key'] === 'otel.metric.overflow'
                    && $this->attributeValue($attribute['value']) === true
                ) {
                    $overflow++;
                }
            }
        }

        self::assertSame(1, $overflow);

        /*
         * The up-down counter is not subject to the counter limit.
         */
        $unlimited = $this->dataPoints($payload, 'test.unlimited.updown');
        self::assertCount(3, $unlimited);
    }

    #[Group('aggregation')]
    public function testReaderCardinalityLimitAppliesToGauge(): void {
        /*
         * The per-instrument gauge limit buckets the second attribute set
         * into the overflow series; the counter keeps both of its sets.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                    cardinality_limits:
                      gauge: 1
            YAML, static function (): void {
            $meter = Globals::meterProvider()->getMeter('config-test');

            $gauge = $meter->createGauge('cardinality.gauge');
            $gauge->record(1, ['k' => 'a']);
            $gauge->record(2, ['k' => 'b']);

            $counter = $meter->createCounter('cardinality.control');
            $counter->add(1, ['k' => 'a']);
            $counter->add(1, ['k' => 'b']);
        });

        $base = '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "cardinality.gauge")].gauge';

        self::assertCount(
            2,
            $this->path($this->metrics[0], $base . '.dataPoints[*]'),
        );
        self::assertSame(
            ['k', 'otel.metric.overflow'],
            $this->path($this->metrics[0], $base . '.dataPoints[*].attributes[*].key'),
        );

        /*
         * The limit is per instrument type: the counter is not subject to
         * the gauge limit and keeps both attribute sets.
         */
        self::assertCount(
            2,
            $this->dataPoints($this->metrics[0], 'cardinality.control'),
        );
    }

    #[Group('aggregation')]
    public function testReaderCardinalityLimitAppliesToHistogram(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                    cardinality_limits:
                      histogram: 1
            YAML, static function (): void {
            $meter = Globals::meterProvider()->getMeter('config-test');

            $histogram = $meter->createHistogram('cardinality.histogram');
            $histogram->record(1, ['k' => 'a']);
            $histogram->record(2, ['k' => 'b']);

            $counter = $meter->createCounter('cardinality.control');
            $counter->add(1, ['k' => 'a']);
            $counter->add(1, ['k' => 'b']);
        });

        $base = '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "cardinality.histogram")].histogram';

        self::assertCount(
            2,
            $this->path($this->metrics[0], $base . '.dataPoints[*].count'),
        );
        self::assertSame(
            ['k', 'otel.metric.overflow'],
            $this->path($this->metrics[0], $base . '.dataPoints[*].attributes[*].key'),
        );

        self::assertCount(
            2,
            $this->dataPoints($this->metrics[0], 'cardinality.control'),
        );
    }

    #[Group('aggregation')]
    public function testReaderCardinalityLimitAppliesToObservableCounter(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                    cardinality_limits:
                      observable_counter: 1
            YAML, static function (): void {
            $meter = Globals::meterProvider()->getMeter('config-test');

            $meter->createObservableCounter('cardinality.observable.counter')
                ->observe(
                    static function (ObserverInterface $observer): void {
                        $observer->observe(1, ['k' => 'a']);
                        $observer->observe(2, ['k' => 'b']);
                    },
                );

            $counter = $meter->createCounter('cardinality.control');
            $counter->add(1, ['k' => 'a']);
            $counter->add(1, ['k' => 'b']);
        });

        $base = '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "cardinality.observable.counter")].sum';

        self::assertCount(
            2,
            $this->path($this->metrics[0], $base . '.dataPoints[*].asInt'),
        );
        self::assertSame(
            ['k', 'otel.metric.overflow'],
            $this->path($this->metrics[0], $base . '.dataPoints[*].attributes[*].key'),
        );

        self::assertCount(
            2,
            $this->dataPoints($this->metrics[0], 'cardinality.control'),
        );
    }

    #[Group('aggregation')]
    public function testReaderCardinalityLimitAppliesToObservableGauge(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                    cardinality_limits:
                      observable_gauge: 1
            YAML, static function (): void {
            $meter = Globals::meterProvider()->getMeter('config-test');

            $meter->createObservableGauge('cardinality.observable.gauge')
                ->observe(
                    static function (ObserverInterface $observer): void {
                        $observer->observe(1, ['k' => 'a']);
                        $observer->observe(2, ['k' => 'b']);
                    },
                );

            $counter = $meter->createCounter('cardinality.control');
            $counter->add(1, ['k' => 'a']);
            $counter->add(1, ['k' => 'b']);
        });

        $base = '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "cardinality.observable.gauge")].gauge';

        self::assertCount(
            2,
            $this->path($this->metrics[0], $base . '.dataPoints[*]'),
        );
        self::assertSame(
            ['k', 'otel.metric.overflow'],
            $this->path($this->metrics[0], $base . '.dataPoints[*].attributes[*].key'),
        );

        self::assertCount(
            2,
            $this->dataPoints($this->metrics[0], 'cardinality.control'),
        );
    }

    #[Group('aggregation')]
    public function testReaderCardinalityLimitAppliesToObservableUpDownCounter(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                    cardinality_limits:
                      observable_up_down_counter: 1
            YAML, static function (): void {
            $meter = Globals::meterProvider()->getMeter('config-test');

            $meter->createObservableUpDownCounter('cardinality.observable.updown')
                ->observe(
                    static function (ObserverInterface $observer): void {
                        $observer->observe(1, ['k' => 'a']);
                        $observer->observe(-2, ['k' => 'b']);
                    },
                );

            $counter = $meter->createCounter('cardinality.control');
            $counter->add(1, ['k' => 'a']);
            $counter->add(1, ['k' => 'b']);
        });

        $base = '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "cardinality.observable.updown")].sum';

        self::assertCount(
            2,
            $this->path($this->metrics[0], $base . '.dataPoints[*].asInt'),
        );
        self::assertSame(
            ['k', 'otel.metric.overflow'],
            $this->path($this->metrics[0], $base . '.dataPoints[*].attributes[*].key'),
        );

        self::assertCount(
            2,
            $this->dataPoints($this->metrics[0], 'cardinality.control'),
        );
    }

    #[Group('aggregation')]
    public function testReaderCardinalityLimitAppliesToUpDownCounter(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                    cardinality_limits:
                      up_down_counter: 1
            YAML, static function (): void {
            $meter = Globals::meterProvider()->getMeter('config-test');

            $upDownCounter = $meter->createUpDownCounter('cardinality.updown');
            $upDownCounter->add(1, ['k' => 'a']);
            $upDownCounter->add(-2, ['k' => 'b']);

            $counter = $meter->createCounter('cardinality.control');
            $counter->add(1, ['k' => 'a']);
            $counter->add(1, ['k' => 'b']);
        });

        $base = '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "cardinality.updown")].sum';

        self::assertCount(
            2,
            $this->path($this->metrics[0], $base . '.dataPoints[*].asInt'),
        );
        self::assertSame(
            ['k', 'otel.metric.overflow'],
            $this->path($this->metrics[0], $base . '.dataPoints[*].attributes[*].key'),
        );

        self::assertCount(
            2,
            $this->dataPoints($this->metrics[0], 'cardinality.control'),
        );
    }

    /*
     * =========================================================================
     * otlp_http exporter options (metrics)
     * =========================================================================
     */

    public function testOtlpHttpExporterHeadersAreSentToCollector(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        headers:
                          - name: auth
                            value: config-token
                          - name: x-custom
                            value: v2
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.headers')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['config-token'], $headers['auth']);
        self::assertSame(['v2'], $headers['x-custom']);
    }

    public function testOtlpHttpHeadersListIsSentToCollector(): void {
        /*
         * headers_list uses the OTEL_EXPORTER_OTLP_HEADERS wire format: a
         * comma separated list of key=value pairs.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        headers_list: "list-header=list-value,x-listed=2"
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.headers-list')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['list-value'], $headers['list-header']);
        self::assertSame(['2'], $headers['x-listed']);
    }

    public function testOtlpHttpHeadersTakePrecedenceOverHeadersList(): void {
        /*
         * An entry present in both headers and headers_list is sent with
         * the headers value; entries only in the list are still sent.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        headers:
                          - name: auth
                            value: from-map
                        headers_list: "auth=from-list,listed-only=1"
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.headers-precedence')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['from-map'], $headers['auth']);
        self::assertSame(['1'], $headers['listed-only']);
    }

    public function testOtlpHttpHeaderWithNullValueIsIgnored(): void {
        /*
         * A headers entry whose value is null is ignored: the header is
         * not sent at all, while sibling entries are unaffected.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        headers:
                          - name: dropped
                            value:
                          - name: kept
                            value: v1
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.headers-null')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertArrayNotHasKey('dropped', $headers);
        self::assertSame(['v1'], $headers['kept']);
    }

    public function testOtlpHttpGzipCompressionIsApplied(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        compression: gzip
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.gzip')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['gzip'], $headers['content-encoding']);

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "config.gzip")]',
            ),
        );
    }

    public function testOtlpHttpCompressionNoneSendsUncompressedExport(): void {
        /*
         * Explicitly selecting the none compression (the default) must not
         * add a content-encoding header.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        compression: none
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.no-compression')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertArrayNotHasKey('content-encoding', $headers);
    }

    public function testOtlpHttpEncodingJsonIsApplied(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        encoding: json
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.json')
                    ->add(1);
            },
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['application/json'], $headers['content-type']);
    }

    #[Group('metrics')]
    public function testOtlpFileMetricExporterWritesNewlineDelimitedJson(): void {
        $file = tempnam(sys_get_temp_dir(), 'otlp-file-metrics');

        try {
            $this->runOTelConfig(
                <<<YAML
                file_format: "1.2"

                meter_provider:
                  readers:
                    - periodic:
                        interval: 60000
                        exporter:
                          otlp_file/development:
                            output_stream: "file://{$file}"
                YAML, static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('file-metrics.counter', 'requests')
                    ->add(5);
            });

            $payloads = array_values(array_filter(
                explode("\n", (string) file_get_contents($file)),
            ));

            self::assertCount(1, $payloads);

            /*
             * Each line is a standalone OTLP/JSON export; json_decode
             * doubles as the well-formedness check.
             */
            json_decode($payloads[0], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(
                ['file-metrics.counter'],
                $this->path(
                    $payloads[0],
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name',
                ),
            );

            self::assertSame(
                ['5'],
                $this->path(
                    $payloads[0],
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[*].sum.dataPoints[*].asInt',
                ),
            );
        } finally {
            @unlink($file);
        }
    }

    #[Group('metrics'), Group('async')]
    public function testOtlpFileMetricExporterDefaultsToCumulativeTemporality(): void {
        $file = tempnam(sys_get_temp_dir(), 'otlp-file-metrics');

        try {
            /*
             * Without a temporality_preference, the spec default (cumulative)
             * applies: each periodic export carries the total since start.
             */
            $output = $this->runOTelConfig(
                <<<YAML
                file_format: "1.2"

                meter_provider:
                  readers:
                    - periodic:
                        interval: 300
                        exporter:
                          otlp_file/development:
                            output_stream: "file://{$file}"
                YAML,
                static function (): void {
                    $counter = Globals::meterProvider()
                        ->getMeter('config-test')
                        ->createCounter('default-temporality.counter', 'requests');

                    $counter->add(5);

                    delay(0.5);

                    $counter->add(3);

                    delay(0.5);

                    echo 'done';
                },
            );

            self::assertSame('done', $output);

            $payloads = array_values(array_filter(
                explode("\n", (string) file_get_contents($file)),
            ));

            self::assertGreaterThanOrEqual(2, count($payloads));

            $exports = [];

            foreach ($payloads as $payload) {
                $dataPoints = $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "default-temporality.counter")].sum.dataPoints[*]',
                );

                if ($dataPoints !== []) {
                    $exports[] = $dataPoints[0];
                }
            }

            $exports = $this->sortByCollectionTime($exports);

            self::assertGreaterThanOrEqual(
                2,
                count($exports),
                'Expected the metric to be exported in multiple collection cycles.',
            );

            self::assertSame('5', $exports[0]['asInt']);
            self::assertSame('8', $exports[array_key_last($exports)]['asInt']);

            /*
             * Cumulative counter values must never decrease between exports;
             * a delta exporter would have reported 5 and 3 instead of 5 and 8.
             */
            $previous = null;

            foreach ($exports as $export) {
                $value = (int) $export['asInt'];

                if ($previous !== null) {
                    self::assertGreaterThanOrEqual(
                        $previous,
                        $value,
                        'Cumulative counter value decreased between exports.',
                    );
                }

                $previous = $value;
            }
        } finally {
            @unlink($file);
        }
    }

    #[Group('metrics')]
    public function testOtlpFileMetricExporterDefaultsToExplicitBucketHistogramAggregation(): void {
        $file = tempnam(sys_get_temp_dir(), 'otlp-file-metrics');

        try {
            /*
             * Without a default_histogram_aggregation, the spec default
             * (explicit_bucket_histogram) applies: data points carry explicit
             * bounds and bucket counts instead of an exponential scale.
             */
            $this->runOTelConfig(
                <<<YAML
                file_format: "1.2"

                meter_provider:
                  readers:
                    - periodic:
                        interval: 60000
                        exporter:
                          otlp_file/development:
                            output_stream: "file://{$file}"
                YAML, static function (): void {
                $histogram = Globals::meterProvider()->getMeter('config-test')
                    ->createHistogram('default-agg.histogram');

                foreach ([0.5, 1, 2, 4, 8, 16] as $value) {
                    $histogram->record($value);
                }
            });

            $payloads = array_values(array_filter(
                explode("\n", (string) file_get_contents($file)),
            ));

            self::assertCount(1, $payloads);

            $dataPoint = $this->path(
                $payloads[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "default-agg.histogram")].histogram.dataPoints[0]',
            )[0];

            self::assertArrayHasKey('explicitBounds', $dataPoint);
            self::assertArrayNotHasKey('scale', $dataPoint);
            self::assertSame(
                6,
                array_sum(array_map('intval', $dataPoint['bucketCounts'])),
            );
        } finally {
            @unlink($file);
        }
    }

    #[Group('async')]
    public function testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow(): void {
        /*
         * The timed-out attempt is retryable, so without an upper bound the
         * exporter would keep retrying with exponential backoff for tens of
         * seconds. The reader's timeout bounds the whole export call (the
         * spec-compliant reader-level limit) so the test stays fast.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 600
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        timeout: 300
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.timeout')
                    ->add(1);
            },
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=' . str_replace(
                '/v1/metrics',
                '/v1/slow',
                $this->env['OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'],
            ),
        );

        /*
         * The export was attempted... the slow route delays its response
         * beyond the 300 ms timeout, so the payload is dropped and the
         * process still shuts down cleanly.
         */
        self::assertGreaterThanOrEqual(1, $this->slowRequests);
        self::assertSame([], $this->metrics);
    }

    public function testOtlpHttpMaxRequestSizeBlocksOversizedExports(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        max_request_size: 1
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.oversized')
                    ->add(1);
            },
        );

        self::assertSame([], $this->metrics);
        self::assertStringContainsString(
            'maximum request size',
            strtolower($this->lastStderr),
        );
    }

    public function testOtlpHttpMaxResponseSizeRejectsLargeResponses(): void {
        /*
         * Unlike max_request_size, the request is sent; the exporter only
         * rejects the collector's response body once it exceeds the
         * configured limit. (JSON encoding is used so that the empty
         * response body is larger than one byte.)
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
                        encoding: json
                        max_response_size: 1
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('config.oversized-response')
                    ->add(1);
            },
        );

        self::assertStringContainsString(
            'buffer length limit',
            strtolower($this->lastStderr),
        );
    }

    public function testConsoleExporterWritesMetricsToStdout(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    exporter:
                      console:
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('console.metric', 'requests')
                    ->add(1);
            },
        );

        /*
         * The specification leaves the console exporter's output format
         * unspecified ("can vary between implementations"), so we only pin
         * down that the metric reaches stdout and does not go to the OTLP
         * HTTP collector.
         */
        self::assertStringContainsString('console.metric', $output);
        self::assertSame([], $this->metrics);
    }

    /*
     * =========================================================================
     * Periodic reader export batching
     * =========================================================================
     */

    #[Group('async')]
    public function testPeriodicReaderMaxExportBatchSizeSplitsExports(): void {
        /*
         * Five series are collected every tick; with a maximum export batch
         * size of two data points, each collection must be split across at
         * least three export requests.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 100
                    max_export_batch_size/development: 2
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('batch-size-test');

                foreach (['one', 'two', 'three', 'four', 'five'] as $name) {
                    $meter->createCounter("test.batch.$name")
                        ->add(1);
                }

                delay(0.4);

                echo 'done';
            },
        );

        self::assertNotEmpty($this->metrics);

        /*
         * No single export request may carry more than two data points...
         */
        foreach ($this->metrics as $payload) {
            $dataPoints = $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[*].sum.dataPoints',
            );

            $count = 0;
            foreach ($dataPoints as $points) {
                $count += count($points);
            }

            self::assertLessThanOrEqual(2, $count);
        }

        /*
         * ...and five series need at least three requests per collection.
         */
        self::assertGreaterThanOrEqual(3, count($this->metrics));
    }

    #[Group('async')]
    public function testPeriodicReaderMaxExportBatchSizeCountsDataPointsNotMetrics(): void {
        /*
         * The batch size bounds data points, not metrics: a single counter
         * with four attribute sets (four data points) must be split across
         * at least two export requests per collection.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 100
                    max_export_batch_size/development: 2
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('batch-size-test')
                    ->createCounter('test.batch.datapoints', 'requests');

                foreach (['a', 'b', 'c', 'd'] as $id) {
                    $counter->add(1, ['request.id' => $id]);
                }

                delay(0.4);

                echo 'done';
            },
        );

        self::assertNotEmpty($this->metrics);

        /*
         * Every request carries at most two data points of the metric...
         */
        foreach ($this->metrics as $payload) {
            $dataPoints = $this->dataPoints(
                $payload,
                'test.batch.datapoints',
            );

            self::assertLessThanOrEqual(2, count($dataPoints));
        }

        /*
         * ...and four data points need at least two requests per collection.
         */
        self::assertGreaterThanOrEqual(2, count($this->metrics));
    }
}

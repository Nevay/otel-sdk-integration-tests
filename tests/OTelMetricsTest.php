<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use PHPUnit\Framework\TestCase;
use Nevay\OTelTest\OTelEndpointTrait;
use OpenTelemetry\API\Globals;
use function Amp\delay;

final class OTelMetricsTest extends TestCase
{
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Pipeline & collection cycles
     * =========================================================================
     */

    public function testMetricsPipelineExportsMultipleInstrumentsAcrossMultipleCollectionCycles(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: metrics-pipeline-test
                - name: service.version
                  value: 1.0.0
                - name: deployment.environment
                  value: test

            meter_provider:
              readers:
                - periodic:
                    interval: 250
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'metrics-test',
                    '1.2.3',
                );

                $requests = $meter->createCounter(
                    'http.server.requests',
                    'requests',
                    'Number of HTTP requests',
                );

                $activeRequests = $meter->createUpDownCounter(
                    'http.server.active_requests',
                    'requests',
                    'Number of active HTTP requests',
                );

                $duration = $meter->createHistogram(
                    'http.server.duration',
                    'ms',
                    'Duration of HTTP requests',
                );

                /*
                 * Cycle 1
                 *
                 * Counter:
                 *   GET /users    = 5
                 *   POST /users   = 2
                 *
                 * UpDownCounter:
                 *   instance-1    = 10
                 *
                 * Histogram:
                 *   GET /users    = 10, 25
                 */
                $requests->add(5, [
                    'http.method' => 'GET',
                    'http.route' => '/users',
                    'http.status_code' => 200,
                ]);

                $requests->add(2, [
                    'http.method' => 'POST',
                    'http.route' => '/users',
                    'http.status_code' => 201,
                ]);

                $activeRequests->add(10, [
                    'service.instance.id' => 'instance-1',
                ]);

                $duration->record(10, [
                    'http.method' => 'GET',
                    'http.route' => '/users',
                ]);

                $duration->record(25, [
                    'http.method' => 'GET',
                    'http.route' => '/users',
                ]);

                delay(0.4);

                /*
                 * Cycle 2
                 *
                 * Counter:
                 *   GET /users    = 5 + 3 = 8
                 *   POST /users   = 2
                 *   GET /health   = 1
                 *
                 * UpDownCounter:
                 *   instance-1    = 10 - 4 = 6
                 *
                 * Histogram:
                 *   GET /users    = 10, 25, 50
                 *   GET /health   = 100
                 */
                $requests->add(3, [
                    'http.method' => 'GET',
                    'http.route' => '/users',
                    'http.status_code' => 200,
                ]);

                $requests->add(1, [
                    'http.method' => 'GET',
                    'http.route' => '/health',
                    'http.status_code' => 200,
                ]);

                $activeRequests->add(-4, [
                    'service.instance.id' => 'instance-1',
                ]);

                $duration->record(50, [
                    'http.method' => 'GET',
                    'http.route' => '/users',
                ]);

                $duration->record(100, [
                    'http.method' => 'GET',
                    'http.route' => '/health',
                ]);

                delay(0.4);

                /*
                 * Cycle 3
                 *
                 * Counter:
                 *   GET /users    = 8
                 *   POST /users   = 2
                 *   GET /health   = 1
                 *   DELETE /users = 10
                 *
                 * UpDownCounter:
                 *   instance-1    = 6 - 6 = 0
                 *
                 * Histogram:
                 *   GET /users     = 10, 25, 50
                 *   GET /health    = 100
                 *   DELETE /users  = 5
                 */
                $requests->add(10, [
                    'http.method' => 'DELETE',
                    'http.route' => '/users/{id}',
                    'http.status_code' => 204,
                ]);

                $activeRequests->add(-6, [
                    'service.instance.id' => 'instance-1',
                ]);

                $duration->record(5, [
                    'http.method' => 'DELETE',
                    'http.route' => '/users/{id}',
                ]);

                delay(0.4);

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        /*
         * With a 250 ms reader interval and 400 ms delays, we expect
         * multiple completed collection/export cycles.
         */
        self::assertGreaterThanOrEqual(
            2,
            count($this->metrics),
            'Expected multiple periodic metric exports.',
        );

        /*
         * We expect the final export to contain all cumulative data.
         */
        $payload = $this->metrics[array_key_last($this->metrics)];

        /*
         * Resource.
         *
         * Other instrumentation may contribute metrics, so we only
         * assert the resource attributes relevant to this test.
         */
        self::assertSame(
            'metrics-pipeline-test',
            $this->resourceAttribute(
                $payload,
                'service.name',
                '$.resourceMetrics[*].resource',
            ),
        );

        self::assertSame(
            '1.0.0',
            $this->resourceAttribute(
                $payload,
                'service.version',
                '$.resourceMetrics[*].resource',
            ),
        );

        self::assertSame(
            'test',
            $this->resourceAttribute(
                $payload,
                'deployment.environment',
                '$.resourceMetrics[*].resource',
            ),
        );

        /*
         * Instrumentation scope.
         *
         * Find the scope belonging specifically to our metric rather
         * than asserting against all scopes in the payload.
         */
        $scope = $this->scopeForMetric(
            $payload,
            'http.server.requests',
        );

        self::assertSame(
            'metrics-test',
            $scope['name'],
        );

        self::assertSame(
            '1.2.3',
            $scope['version'],
        );

        /*
         * Counter metadata.
         */
        $requests = $this->metric(
            $payload,
            'http.server.requests',
        );

        self::assertSame(
            'Number of HTTP requests',
            $requests['description'],
        );

        self::assertSame(
            'requests',
            $requests['unit'],
        );

        /*
         * Counter series.
         *
         * Counters are cumulative, so the final export must contain
         * the sum of all additions made during the process.
         */
        self::assertSame(
            '8',
            $this->dataPoint(
                $payload,
                'http.server.requests',
                [
                    'http.method' => 'GET',
                    'http.route' => '/users',
                    'http.status_code' => '200',
                ],
            )['asInt'],
        );

        self::assertSame(
            '2',
            $this->dataPoint(
                $payload,
                'http.server.requests',
                [
                    'http.method' => 'POST',
                    'http.route' => '/users',
                    'http.status_code' => '201',
                ],
            )['asInt'],
        );

        self::assertSame(
            '1',
            $this->dataPoint(
                $payload,
                'http.server.requests',
                [
                    'http.method' => 'GET',
                    'http.route' => '/health',
                    'http.status_code' => '200',
                ],
            )['asInt'],
        );

        self::assertSame(
            '10',
            $this->dataPoint(
                $payload,
                'http.server.requests',
                [
                    'http.method' => 'DELETE',
                    'http.route' => '/users/{id}',
                    'http.status_code' => '204',
                ],
            )['asInt'],
        );

        /*
         * UpDownCounter.
         *
         * 10 - 4 - 6 = 0
         */
        self::assertSame(
            '0',
            $this->dataPoint(
                $payload,
                'http.server.active_requests',
                [
                    'service.instance.id' => 'instance-1',
                ],
            )['asInt'],
        );

        /*
         * Histogram: GET /users.
         *
         * Five observations:
         *
         *   10 + 25 + 50 = 85
         */
        $usersDuration = $this->dataPoint(
            $payload,
            'http.server.duration',
            [
                'http.method' => 'GET',
                'http.route' => '/users',
            ],
            'histogram',
        );

        self::assertSame(
            '3',
            $usersDuration['count'],
        );

        self::assertSame(
            85,
            $usersDuration['sum'],
        );

        self::assertSame(
            10,
            $usersDuration['min'],
        );

        self::assertSame(
            50,
            $usersDuration['max'],
        );

        /*
         * Histogram: GET /health.
         */
        $healthDuration = $this->dataPoint(
            $payload,
            'http.server.duration',
            [
                'http.method' => 'GET',
                'http.route' => '/health',
            ],
            'histogram',
        );

        self::assertSame(
            '1',
            $healthDuration['count'],
        );

        self::assertSame(
            100,
            $healthDuration['sum'],
        );

        self::assertSame(
            100,
            $healthDuration['min'],
        );

        self::assertSame(
            100,
            $healthDuration['max'],
        );

        /*
         * Histogram: DELETE /users/{id}.
         */
        $deleteDuration = $this->dataPoint(
            $payload,
            'http.server.duration',
            [
                'http.method' => 'DELETE',
                'http.route' => '/users/{id}',
            ],
            'histogram',
        );

        self::assertSame(
            '1',
            $deleteDuration['count'],
        );

        self::assertSame(
            5,
            $deleteDuration['sum'],
        );

        self::assertSame(
            5,
            $deleteDuration['min'],
        );

        self::assertSame(
            5,
            $deleteDuration['max'],
        );
    }

    private function scopeForMetric(
        string $payload,
        string $metricName,
    ): array {
        $scopeMetrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*]',
        );

        foreach ($scopeMetrics as $scopeMetric) {
            foreach ($scopeMetric['metrics'] ?? [] as $metric) {
                if (($metric['name'] ?? null) !== $metricName) {
                    continue;
                }

                self::assertArrayHasKey(
                    'scope',
                    $scopeMetric,
                    sprintf(
                        'Scope was not found for metric "%s".',
                        $metricName,
                    ),
                );

                return $scopeMetric['scope'];
            }
        }

        self::fail(sprintf(
            'Metric "%s" was not found in any instrumentation scope.',
            $metricName,
        ));
    }

    private function metric(
        string $payload,
        string $name,
    ): array {
        $metrics = $this->path(
            $payload,
            sprintf(
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "%s")]',
                $name,
            ),
        );

        self::assertNotEmpty(
            $metrics,
            sprintf('Metric "%s" was not found.', $name),
        );

        return $metrics[0];
    }

    private function dataPoints(
        string $payload,
        string $metricName,
        string $type = 'sum',
    ): array {
        return $this->path(
            $payload,
            sprintf(
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "%s")].%s.dataPoints[*]',
                $metricName,
                $type,
            ),
        );
    }

    private function dataPoint(
        string $payload,
        string $metricName,
        array $attributes = [],
        string $type = 'sum',
    ): array {
        $dataPoints = $this->dataPoints(
            $payload,
            $metricName,
            $type,
        );

        foreach ($dataPoints as $dataPoint) {
            $actualAttributes = [];

            foreach ($dataPoint['attributes'] ?? [] as $attribute) {
                $actualAttributes[$attribute['key']] = $this->attributeValue(
                    $attribute['value'] ?? [],
                );
            }

            if ($actualAttributes === $attributes) {
                return $dataPoint;
            }
        }

        self::fail(sprintf(
            'Data point for metric "%s" with attributes %s was not found.',
            $metricName,
            json_encode($attributes, JSON_THROW_ON_ERROR),
        ));
    }

    private function attributeValue(array $value): mixed
    {
        return $value['stringValue']
            ?? $value['intValue']
            ?? $value['doubleValue']
            ?? $value['boolValue']
            ?? $value['bytesValue']
            ?? null;
    }

    public function testMetricsPipelineKeepsMetricSeriesSeparateByAttributes(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('attributes-test');

                $counter = $meter->createCounter(
                    'jobs.processed',
                    'jobs',
                    'Number of processed jobs',
                );

                // Collection 1.
                $counter->add(10, [
                    'queue' => 'default',
                    'worker' => 'worker-1',
                ]);

                $counter->add(20, [
                    'queue' => 'default',
                    'worker' => 'worker-2',
                ]);

                $counter->add(30, [
                    'queue' => 'priority',
                    'worker' => 'worker-1',
                ]);

                delay(0.3);

                // Collection 2.
                $counter->add(5, [
                    'queue' => 'default',
                    'worker' => 'worker-1',
                ]);

                $counter->add(40, [
                    'queue' => 'priority',
                    'worker' => 'worker-2',
                ]);

                delay(0.3);

                echo json_encode([
                    'completed' => true,
                ], JSON_THROW_ON_ERROR);
            },
        );

        $result = json_decode(
            $output,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertTrue($result['completed']);

        self::assertGreaterThanOrEqual(
            2,
            count($this->metrics),
            'Expected multiple periodic metric exports.',
        );

        $payload = $this->lastMetricExport();

        self::assertSame(
            '15',
            $this->dataPoint(
                $payload,
                'jobs.processed',
                [
                    'queue' => 'default',
                    'worker' => 'worker-1',
                ],
            )['asInt'],
        );

        self::assertSame(
            '20',
            $this->dataPoint(
                $payload,
                'jobs.processed',
                [
                    'queue' => 'default',
                    'worker' => 'worker-2',
                ],
            )['asInt'],
        );

        self::assertSame(
            '30',
            $this->dataPoint(
                $payload,
                'jobs.processed',
                [
                    'queue' => 'priority',
                    'worker' => 'worker-1',
                ],
            )['asInt'],
        );

        self::assertSame(
            '40',
            $this->dataPoint(
                $payload,
                'jobs.processed',
                [
                    'queue' => 'priority',
                    'worker' => 'worker-2',
                ],
            )['asInt'],
        );
    }

    public function testMetricsPipelineExportsHistogramAggregationAcrossCollections(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('histogram-test');

                $histogram = $meter->createHistogram(
                    'request.duration',
                    'ms',
                    'Request duration',
                );

                // Collection 1.
                $histogram->record(5, [
                    'route' => '/users',
                ]);

                $histogram->record(10, [
                    'route' => '/users',
                ]);

                $histogram->record(20, [
                    'route' => '/users',
                ]);

                delay(0.3);

                // Collection 2.
                $histogram->record(50, [
                    'route' => '/users',
                ]);

                $histogram->record(100, [
                    'route' => '/users',
                ]);

                $histogram->record(15, [
                    'route' => '/health',
                ]);

                delay(0.3);

                echo json_encode([
                    'completed' => true,
                ], JSON_THROW_ON_ERROR);
            },
        );

        $result = json_decode(
            $output,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertTrue($result['completed']);

        self::assertGreaterThanOrEqual(
            2,
            count($this->metrics),
            'Expected multiple periodic metric exports.',
        );

        $payload = $this->lastMetricExport();

        $metric = $this->metric(
            $payload,
            'request.duration',
        );

        self::assertSame(
            'Request duration',
            $metric['description'],
        );

        self::assertSame(
            'ms',
            $metric['unit'],
        );

        $users = $this->dataPoint(
            $payload,
            'request.duration',
            ['route' => '/users'],
            'histogram',
        );

        self::assertSame('5', $users['count']);
        self::assertSame(185, $users['sum']);
        self::assertSame(5, $users['min']);
        self::assertSame(100, $users['max']);

        $health = $this->dataPoint(
            $payload,
            'request.duration',
            ['route' => '/health'],
            'histogram',
        );

        self::assertSame('1', $health['count']);
        self::assertSame(15, $health['sum']);
        self::assertSame(15, $health['min']);
        self::assertSame(15, $health['max']);
    }

    public function testMetricsGaugeExportsLastValuePerCollection(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 200
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $gauge = Globals::meterProvider()
                    ->getMeter('gauge-test')
                    ->createGauge('probe.gauge');

                $gauge->record(1);

                delay(0.35);

                $gauge->record(9);

                delay(0.35);

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        $exports = [];

        foreach ($this->metrics as $payload) {
            $dataPoints = $this->dataPoints(
                $payload,
                'probe.gauge',
                'gauge',
            );

            if ($dataPoints !== []) {
                $exports[] = $dataPoints[0];
            }
        }

        self::assertGreaterThanOrEqual(
            2,
            count($exports),
            'Expected the gauge to be exported in multiple collection cycles.',
        );

        /*
         * The first collection happens before the second record, so it
         * must report the first recorded value...
         */
        self::assertSame('1', $exports[0]['asInt']);

        /*
         * ...and every subsequent collection reports the latest value,
         * even when no new record was made.
         */
        foreach (array_slice($exports, 1) as $export) {
            self::assertSame('9', $export['asInt']);
        }
    }

    public function testMultipleMetersProduceSeparateScopesInOneExport(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 200
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $meterA = Globals::meterProvider()->getMeter(
                    'meter-a',
                    '1.0.0',
                );
                $meterB = Globals::meterProvider()->getMeter(
                    'meter-b',
                    '2.0.0',
                );

                $meterA->createCounter('scope.a')->add(1);
                $meterB->createCounter('scope.b')->add(2);

                delay(0.35);

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        $payload = $this->lastMetricExport();

        /*
         * Each meter maps to its own scopeMetrics entry, keeping its
         * name and version.
         */
        $scopes = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].scope',
        );

        self::assertCount(2, $scopes);

        $scopeNames = array_column($scopes, 'name');
        $scopeVersions = array_column($scopes, 'version');

        self::assertSame(['1.0.0', '2.0.0'], $scopeVersions);
        self::assertContains('meter-a', $scopeNames);
        self::assertContains('meter-b', $scopeNames);

        /*
         * Each metric is only reported by the scope of its own meter.
         */
        $metricScopes = [];

        foreach ($this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*]',
        ) as $scopeMetric) {
            foreach ($scopeMetric['metrics'] ?? [] as $metric) {
                $metricScopes[$metric['name']] = $scopeMetric['scope']['name'];
            }
        }

        self::assertSame('meter-a', $metricScopes['scope.a']);
        self::assertSame('meter-b', $metricScopes['scope.b']);
    }

    public function testUpDownCounterExportsNonMonotonicSum(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 200
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                $active = Globals::meterProvider()
                    ->getMeter('updown-test')
                    ->createUpDownCounter('active.requests');

                $active->add(5);

                delay(0.25);

                $active->add(-2);

                delay(0.3);

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        $values = [];

        foreach ($this->metrics as $payload) {
            $dataPoints = $this->dataPoints(
                $payload,
                'active.requests',
            );

            if ($dataPoints !== []) {
                $values[] = $dataPoints[0]['asInt'];
            }
        }

        self::assertNotEmpty($values);

        /*
         * The cumulative sum includes the negative addition, so the
         * final collection reports the net value 5 + (-2) = 3.
         */
        self::assertSame('3', $values[array_key_last($values)]);

        /*
         * Only the two intermediate totals can be observed...
         */
        foreach ($values as $value) {
            self::assertContains($value, ['5', '3']);
        }

        /*
         * ...and a cumulative sum only ever decreases here once the
         * negative addition has been made.
         */
        if (in_array('5', $values, true) && in_array('3', $values, true)) {
            self::assertLessThan(
                array_search('3', $values, true),
                array_search('5', $values, true),
            );
        }
    }

    private function lastMetricExport(): string
    {
        self::assertNotEmpty(
            $this->metrics,
            'No metric export was received.',
        );

        return $this->metrics[array_key_last($this->metrics)];
    }

    /*
     * =========================================================================
     * Temporality
     * =========================================================================
     */

    public function testMetricsExporterUsesCumulativeTemporality(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
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

                delay(0.4);

                $counter->add(3);

                delay(0.4);

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

    public function testMetricsExporterUsesDeltaTemporality(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
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

                delay(0.4);

                $counter->add(3);

                delay(0.4);

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

    public function testMetricsCumulativeTemporalityPreservesStartTimestamp(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
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

                delay(0.4);

                $counter->add(3);

                delay(0.4);

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

    /*
     * =========================================================================
     * Cardinality limit
     * =========================================================================
     */

    public function testMetricsCardinalityLimitLimitsNumberOfAttributeSets(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: test.requests
                  stream:
                    aggregation_cardinality_limit: 2
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'cardinality-test',
                    '1.0.0',
                );

                $counter = $meter->createCounter(
                    'test.requests',
                    'requests',
                );

                $counter->add(1, [
                    'request.id' => 'request-1',
                ]);

                $counter->add(2, [
                    'request.id' => 'request-2',
                ]);

                $counter->add(4, [
                    'request.id' => 'request-3',
                ]);

                $counter->add(10, [
                    'request.id' => 'request-1',
                ]);

                $counter->add(20, [
                    'request.id' => 'request-2',
                ]);

                $counter->add(40, [
                    'request.id' => 'request-3',
                ]);

                delay(0.4);

                echo 'done';
            },
        );

        self::assertSame('done', $output);

        self::assertNotEmpty(
            $this->metrics,
            'Expected at least one metric export.',
        );

        $payload = $this->metrics[array_key_last($this->metrics)];

        $dataPoints = $this->dataPoints(
            $payload,
            'test.requests',
        );

        self::assertCount(
            3,
            $dataPoints,
            'Expected two regular series and one overflow series.',
        );

        /*
         * Exactly two data points must have request.id.
         */
        $regularSeries = [];

        foreach ($dataPoints as $dataPoint) {
            foreach ($dataPoint['attributes'] ?? [] as $attribute) {
                if ($attribute['key'] !== 'request.id') {
                    continue;
                }

                $regularSeries[] = $this->attributeValue(
                    $attribute['value'],
                );
            }
        }

        self::assertCount(2, $regularSeries);

        self::assertCount(
            2,
            array_unique($regularSeries),
        );

        /*
         * One data point must be the overflow aggregation.
         */
        $overflowPoints = [];

        foreach ($dataPoints as $dataPoint) {
            foreach ($dataPoint['attributes'] ?? [] as $attribute) {
                if (
                    $attribute['key'] === 'otel.metric.overflow'
                    && $this->attributeValue($attribute['value']) === true
                ) {
                    $overflowPoints[] = $dataPoint;
                }
            }
        }

        self::assertCount(
            1,
            $overflowPoints,
            'Expected exactly one overflow aggregation.',
        );
    }

    /*
     * =========================================================================
     * Views
     * =========================================================================
     */

    public function testMetricsViewCanFilterAttributes(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: test.requests
                  stream:
                    attribute_keys:
                      included:
                        - http.method
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'view-test',
                    '1.0.0',
                );

                $counter = $meter->createCounter(
                    'test.requests',
                    'requests',
                );

                $counter->add(1, [
                    'http.method' => 'GET',
                    'http.route' => '/users',
                    'http.status_code' => 200,
                ]);

                $counter->add(2, [
                    'http.method' => 'GET',
                    'http.route' => '/orders',
                    'http.status_code' => 200,
                ]);

                $counter->add(4, [
                    'http.method' => 'POST',
                    'http.route' => '/users',
                    'http.status_code' => 201,
                ]);

                delay(0.4);

                echo 'done';
            },
        );

        self::assertSame('done', $output);
        self::assertNotEmpty($this->metrics);

        $payload = $this->metrics[array_key_last($this->metrics)];

        $dataPoints = $this->dataPoints(
            $payload,
            'test.requests',
        );

        self::assertCount(2, $dataPoints);

        self::assertSame(
            '3',
            $this->dataPoint(
                $payload,
                'test.requests',
                ['http.method' => 'GET'],
            )['asInt'],
        );

        self::assertSame(
            '4',
            $this->dataPoint(
                $payload,
                'test.requests',
                ['http.method' => 'POST'],
            )['asInt'],
        );

        foreach ($dataPoints as $dataPoint) {
            $attributeNames = array_map(
                static fn (array $attribute): string => $attribute['key'],
                $dataPoint['attributes'] ?? [],
            );

            self::assertSame(
                ['http.method'],
                $attributeNames,
            );
        }
    }

    public function testMetricsInstrumentCanProduceMultipleViewStreams(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: test.requests
                  stream:
                    name: test.requests.total
                    attribute_keys:
                      included:
                        - http.method

                - selector:
                    instrument_name: test.requests
                  stream:
                    name: test.requests.by_route
                    attribute_keys:
                      included:
                        - http.route
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'view-test',
                    '1.0.0',
                );

                $counter = $meter->createCounter(
                    'test.requests',
                    'requests',
                );

                $counter->add(3, [
                    'http.method' => 'GET',
                    'http.route' => '/users',
                ]);

                $counter->add(2, [
                    'http.method' => 'GET',
                    'http.route' => '/orders',
                ]);

                delay(0.4);

                echo 'done';
            },
        );

        self::assertSame('done', $output);
        self::assertNotEmpty($this->metrics);

        $payload = $this->metrics[array_key_last($this->metrics)];

        $methodMetric = $this->metric(
            $payload,
            'test.requests.total',
        );

        $routeMetric = $this->metric(
            $payload,
            'test.requests.by_route',
        );

        self::assertSame(
            '5',
            $this->dataPoint(
                $payload,
                'test.requests.total',
                ['http.method' => 'GET'],
            )['asInt'],
        );

        self::assertSame(
            '3',
            $this->dataPoint(
                $payload,
                'test.requests.by_route',
                ['http.route' => '/users'],
            )['asInt'],
        );

        self::assertSame(
            '2',
            $this->dataPoint(
                $payload,
                'test.requests.by_route',
                ['http.route' => '/orders'],
            )['asInt'],
        );
    }

    public function testMetricsViewCanOverrideMetricMetadata(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    interval: 250
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: test.requests
                  stream:
                    name: http.server.requests
                    description: HTTP server request count
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter(
                    'view-test',
                    '1.0.0',
                );

                $counter = $meter->createCounter(
                    'test.requests',
                    'items',
                    'Original description',
                );

                $counter->add(1);

                delay(0.4);

                echo 'done';
            },
        );

        self::assertSame('done', $output);
        self::assertNotEmpty($this->metrics);

        $payload = $this->metrics[array_key_last($this->metrics)];

        $metric = $this->metric(
            $payload,
            'http.server.requests',
        );

        self::assertSame(
            'HTTP server request count',
            $metric['description'],
        );

        self::assertSame(
            '1',
            $this->dataPoint(
                $payload,
                'http.server.requests',
            )['asInt'],
        );
    }

}

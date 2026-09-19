<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

#[Group('spec')]
#[Group('config-file'), Group('metrics')]
final class ConfigViewsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Views
     * =========================================================================
     */

    public function testViewSelectsByInstrumentTypeAndUnit(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_type: histogram
                    unit: ms
                  stream:
                    name: latency.selected
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createHistogram('latency', 'ms')
                    ->record(10);

                $meter
                    ->createHistogram('latency.bytes', 'bytes')
                    ->record(10);

                $meter
                    ->createCounter('latency.counter', 'ms')
                    ->add(1);
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.selected")]',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.bytes")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.counter")]',
            ),
        );
    }

    public function testViewSelectsByMeterNameVersionAndSchemaUrl(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                    meter_name: selected-meter
                    meter_version: "1.2.3"
                    meter_schema_url: https://example.com/schema
                  stream:
                    name: selected.requests
            YAML,
            static function (): void {
                $selected = Globals::meterProvider()->getMeter(
                    'selected-meter',
                    '1.2.3',
                    'https://example.com/schema',
                );

                $selected
                    ->createCounter('requests')
                    ->add(1);

                $wrongVersion = Globals::meterProvider()->getMeter(
                    'selected-meter',
                    '9.9.9',
                    'https://example.com/schema',
                );

                $wrongVersion
                    ->createCounter('requests')
                    ->add(1);

                $wrongMeter = Globals::meterProvider()->getMeter(
                    'other-meter',
                    '1.2.3',
                    'https://example.com/schema',
                );

                $wrongMeter
                    ->createCounter('requests')
                    ->add(1);
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.requests")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.requests")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[?(@.scope.name == "selected-meter")].metrics[?(@.name == "selected.requests")]',
            ),
        );
    }

    public function testViewSelectsByMeterName(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.meter_name.requests
                    meter_name: selected-meter
                  stream:
                    name: selected.meter_name.requests
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('selected-meter')
                    ->createCounter('view.meter_name.requests')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter('other-meter')
                    ->createCounter('view.meter_name.requests')
                    ->add(2);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_name.requests")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_name.requests")]',
            ),
        );

        self::assertSame(
            '1',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_name.requests")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertSame(
            '2',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_name.requests")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewSelectsByMeterVersion(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.meter_version.requests
                    meter_name: versioned-meter
                    meter_version: "1.0.0"
                  stream:
                    name: selected.meter_version.requests
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('versioned-meter', '1.0.0')
                    ->createCounter('view.meter_version.requests')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter('versioned-meter', '2.0.0')
                    ->createCounter('view.meter_version.requests')
                    ->add(2);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_version.requests")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_version.requests")]',
            ),
        );

        self::assertSame(
            '1',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_version.requests")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertSame(
            '2',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_version.requests")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewSelectsByMeterSchemaUrl(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.meter_schema.requests
                    meter_name: schema-meter
                    meter_schema_url: https://example.test/schema/one
                  stream:
                    name: selected.meter_schema.requests
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter(
                        'schema-meter',
                        '1.0.0',
                        'https://example.test/schema/one',
                    )
                    ->createCounter('view.meter_schema.requests')
                    ->add(1);

                Globals::meterProvider()
                    ->getMeter(
                        'schema-meter',
                        '1.0.0',
                        'https://example.test/schema/two',
                    )
                    ->createCounter('view.meter_schema.requests')
                    ->add(2);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_schema.requests")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_schema.requests")]',
            ),
        );

        self::assertSame(
            '1',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.meter_schema.requests")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertSame(
            '2',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.meter_schema.requests")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewSelectorRequiresAllSpecifiedCriteriaToMatch(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: latency
                    instrument_type: histogram
                    unit: ms
                  stream:
                    name: selected.latency
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                // Matches name + type + unit.
                $meter
                    ->createHistogram('latency', 'ms')
                    ->record(10);

                // Matches name + type, but not unit.
                $meter
                    ->createHistogram('latency.seconds', 's')
                    ->record(10);

                // Matches name + unit, but not type.
                $meter
                    ->createCounter('latency.counter', 'ms')
                    ->add(10);

                // Matches type + unit, but not name.
                $meter
                    ->createHistogram('other', 'ms')
                    ->record(10);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.latency")]',
            ),
        );

        // Non-matching instruments continue to be exported normally.
        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.seconds")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency.counter")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "other")]',
            ),
        );

        self::assertSame(
            10,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.latency")].histogram.dataPoints[*].sum',
            )[0],
        );
    }

    public function testViewSelectorMatchesAllSpecifiedInstrumentAndMeterCriteria(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.all.criteria
                    instrument_type: counter
                    unit: requests
                    meter_name: selected-meter
                    meter_version: "1.2.3"
                    meter_schema_url: https://example.test/schema
                  stream:
                    name: view.all.criteria.selected
            YAML,
            static function (): void {
                $selected = Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    );

                $selected
                    ->createCounter('view.all.criteria', 'requests')
                    ->add(1);

                // Different instrument name.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    )
                    ->createCounter('view.all.criteria.other-name', 'requests')
                    ->add(2);

                // Different instrument type.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    )
                    ->createHistogram('view.all.criteria', 'requests')
                    ->record(3);

                // Different unit.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    )
                    ->createCounter('view.all.criteria', 'other')
                    ->add(4);

                // Different meter name.
                Globals::meterProvider()
                    ->getMeter(
                        'other-meter',
                        '1.2.3',
                        'https://example.test/schema',
                    )
                    ->createCounter('view.all.criteria', 'requests')
                    ->add(5);

                // Different meter version.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '9.9.9',
                        'https://example.test/schema',
                    )
                    ->createCounter('view.all.criteria', 'requests')
                    ->add(6);

                // Different schema URL.
                Globals::meterProvider()
                    ->getMeter(
                        'selected-meter',
                        '1.2.3',
                        'https://example.test/other-schema',
                    )
                    ->createCounter('view.all.criteria', 'requests')
                    ->add(7);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.all.criteria.selected")]',
            ),
        );

        self::assertSame(
            ['1'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.all.criteria.selected")].sum.dataPoints[*].asInt',
            ),
        );

        // Every non-matching instrument remains exported under its original name.
        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.all.criteria.other-name")]',
            ),
        );

        // The original instrument name has multiple non-matching instruments,
        // so verify their values rather than asserting a single metric.
        $originalMetricValues = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.all.criteria")].sum.dataPoints[*].asInt',
        );

        self::assertContains('4', $originalMetricValues);
        self::assertContains('5', $originalMetricValues);
        self::assertContains('6', $originalMetricValues);
        self::assertContains('7', $originalMetricValues);
    }

    public function testViewWithEmptySelectorMatchesEveryInstrument(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector: {}
                  stream:
                    name: all.instruments
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('counter')
                    ->add(1, ['test.instrument' => 'counter']);

                $meter
                    ->createHistogram('histogram')
                    ->record(2, ['test.instrument' => 'histogram']);

                $meter
                    ->createGauge('gauge')
                    ->record(3, ['test.instrument' => 'gauge']);
            },
        );

        $payload = $this->metrics[0];

        // An empty selector matches every instrument, including instruments
        // created by installed auto-instrumentation. Therefore, don't assert
        // an exact number of "all.instruments" metrics/data points.

        $counterTestAttributes = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "all.instruments")].sum.dataPoints[*].attributes[?(@.key == "test.instrument")].value.stringValue',
        );

        self::assertContains('counter', $counterTestAttributes);

        $histogramTestAttributes = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "all.instruments")].histogram.dataPoints[*].attributes[?(@.key == "test.instrument")].value.stringValue',
        );

        self::assertContains('histogram', $histogramTestAttributes);

        $gaugeTestAttributes = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "all.instruments")].gauge.dataPoints[*].attributes[?(@.key == "test.instrument")].value.stringValue',
        );

        self::assertContains('gauge', $gaugeTestAttributes);
    }

    public function testViewFiltersAttributeKeys(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    attribute_keys:
                      included:
                        - http.method
                        - http.route
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests')
                    ->add(
                        1,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/users',
                            'http.status_code' => 200,
                        ],
                    );
            },
        );

        $attributes = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[*]',
        );

        self::assertCount(2, $attributes);

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "http.method")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "http.route")]',
            ),
        );

        self::assertEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "http.status_code")]',
            ),
        );
    }

    #[Group('async')]
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

    public function testViewAttributeKeysSupportIncludeAndExcludePatterns(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    attribute_keys:
                      included:
                        - http.*
                      excluded:
                        - http.user_agent
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests')
                    ->add(
                        1,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/users',
                            'http.user_agent' => 'test-agent',
                            'other.attribute' => 'ignored',
                        ],
                    );
            },
        );

        $payload = $this->metrics[0];

        $keys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[*].key',
        );

        self::assertCount(2, $keys);
        self::assertContains('http.method', $keys);
        self::assertContains('http.route', $keys);
        self::assertNotContains('http.user_agent', $keys);
        self::assertNotContains('other.attribute', $keys);
    }

    public function testViewExcludedAttributesTakePrecedenceOverIncludedAttributes(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.attribute.precedence
                  stream:
                    attribute_keys:
                      included:
                        - http.*
                      excluded:
                        - http.user_agent
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('view.attribute.precedence')
                    ->add(
                        1,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/test',
                            'http.user_agent' => 'test-agent',
                            'other.attribute' => 'ignored',
                        ],
                    );
            },
        );

        $payload = $this->metrics[0];

        $keys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.attribute.precedence")].sum.dataPoints[*].attributes[*].key',
        );

        self::assertContains('http.method', $keys);
        self::assertContains('http.route', $keys);
        self::assertNotContains('http.user_agent', $keys);
        self::assertNotContains('other.attribute', $keys);
    }

    public function testViewAttributeFilteringOccursBeforeCardinalityLimit(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.cardinality.after.filtering
                  stream:
                    attribute_keys:
                      included:
                        - region
                    aggregation_cardinality_limit: 2
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('view.cardinality.after.filtering');

                $counter->add(
                    1,
                    [
                        'region' => 'eu',
                        'request_id' => 'request-1',
                    ],
                );

                $counter->add(
                    1,
                    [
                        'region' => 'eu',
                        'request_id' => 'request-2',
                    ],
                );

                $counter->add(
                    1,
                    [
                        'region' => 'us',
                        'request_id' => 'request-3',
                    ],
                );
            },
        );

        $payload = $this->metrics[0];

        $dataPoints = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.cardinality.after.filtering")].sum.dataPoints[*]',
        );

        self::assertCount(2, $dataPoints);

        $regions = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.cardinality.after.filtering")].sum.dataPoints[*].attributes[?(@.key == "region")].value.stringValue',
        );

        self::assertContains('eu', $regions);
        self::assertContains('us', $regions);

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.cardinality.after.filtering")].sum.dataPoints[*].attributes[?(@.key == "otel.metric.overflow")]',
            ),
        );

        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.cardinality.after.filtering")].sum.dataPoints[*].asInt',
        );

        self::assertContains('2', $values);
        self::assertContains('1', $values);
    }

    public function testViewExplicitDefaultAggregationUsesInstrumentKind(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.default.counter
                  stream:
                    aggregation:
                      default:

                - selector:
                    instrument_name: view.default.gauge
                  stream:
                    aggregation:
                      default:

                - selector:
                    instrument_name: view.default.histogram
                  stream:
                    aggregation:
                      default:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('view.default.counter')
                    ->add(5);

                $meter
                    ->createGauge('view.default.gauge')
                    ->record(7);

                $meter
                    ->createHistogram('view.default.histogram')
                    ->record(9);
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.counter")].sum',
            ),
        );

        self::assertSame(
            '5',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.counter")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.gauge")].gauge',
            ),
        );

        self::assertSame(
            '7',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.gauge")].gauge.dataPoints[*].asInt',
            )[0],
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.histogram")].histogram',
            ),
        );

        self::assertSame(
            '1',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.histogram")].histogram.dataPoints[*].count',
            )[0],
        );

        self::assertSame(
            9,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.default.histogram")].histogram.dataPoints[*].sum',
            )[0],
        );
    }

    public function testViewUsesExplicitBucketHistogramAggregation(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: latency
                  stream:
                    aggregation:
                      explicit_bucket_histogram:
                        boundaries:
                          - 10
                          - 100
                        record_min_max: true
            YAML,
            static function (): void {
                $histogram = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createHistogram('latency', 'ms');

                $histogram->record(5);
                $histogram->record(50);
                $histogram->record(150);
            },
        );

        $payload = $this->metrics[0];

        self::assertSame(
            [10, 100],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")].histogram.dataPoints[*].explicitBounds',
            )[0],
        );

        self::assertSame(
            ['1', '1', '1'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")].histogram.dataPoints[*].bucketCounts',
            )[0],
        );

        self::assertSame(
            5,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")].histogram.dataPoints[*].min',
            )[0],
        );

        self::assertSame(
            150,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "latency")].histogram.dataPoints[*].max',
            )[0],
        );
    }

    public function testViewCanDropAnInstrument(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: noisy.requests
                  stream:
                    aggregation:
                      drop:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('noisy.requests')
                    ->add(10);

                $meter
                    ->createCounter('normal.requests')
                    ->add(20);
            },
        );

        $payload = $this->metrics[0];

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "noisy.requests")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "normal.requests")]',
            ),
        );
    }

    public function testViewSupportsSumAndLastValueAggregations(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: counter.sum
                  stream:
                    aggregation:
                      sum:

                - selector:
                    instrument_name: gauge.last
                  stream:
                    aggregation:
                      last_value:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $counter = $meter->createCounter('counter.sum');
                $counter->add(2);
                $counter->add(3);

                $gauge = $meter->createGauge('gauge.last');
                $gauge->record(2);
                $gauge->record(3);
            },
        );

        $payload = $this->metrics[0];

        $sumMetrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "counter.sum")]',
        );

        self::assertCount(1, $sumMetrics);

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "counter.sum")].sum',
            ),
        );

        self::assertSame(
            '5',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "counter.sum")].sum.dataPoints[*].asInt',
            )[0],
        );

        $lastValueMetrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "gauge.last")]',
        );

        self::assertCount(1, $lastValueMetrics);

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "gauge.last")].gauge',
            ),
        );

        self::assertSame(
            '3',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "gauge.last")].gauge.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewAggregationCardinalityLimitUsesOverflowSeries(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    aggregation_cardinality_limit: 2
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests');

                $counter->add(1, ['region' => 'eu']);
                $counter->add(2, ['region' => 'us']);
                $counter->add(3, ['region' => 'ap']);
            },
        );

        $payload = $this->metrics[0];

        $dataPoints = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*]',
        );

        // Two normal series plus the overflow series.
        self::assertCount(3, $dataPoints);

        $regions = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "region")].value.stringValue',
        );

        self::assertCount(2, $regions);
        self::assertContains('eu', $regions);
        self::assertContains('us', $regions);

        self::assertSame(
            [true],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "otel.metric.overflow")].value.boolValue',
            ),
        );

        // Every measurement must be represented exactly once.
        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].asInt',
        );

        self::assertCount(3, $values);
        self::assertContains('1', $values);
        self::assertContains('2', $values);
        self::assertContains('3', $values);
    }

    public function testViewAggregationPreservesInstrumentAttributes(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    aggregation:
                      sum:
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests');

                $counter->add(1, ['http.method' => 'GET']);
                $counter->add(2, ['http.method' => 'POST']);
            },
        );

        $payload = $this->metrics[0];

        $dataPoints = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*]',
        );

        self::assertCount(2, $dataPoints);

        $methods = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].attributes[?(@.key == "http.method")].value.stringValue',
        );

        self::assertCount(2, $methods);
        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);

        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].sum.dataPoints[*].asInt',
        );

        self::assertCount(2, $values);
        self::assertContains('1', $values);
        self::assertContains('2', $values);
    }

    public function testViewRenamesMetricAndChangesDescription(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    name: http.server.requests
                    description: HTTP server request count
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests')
                    ->add(3);
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.server.requests")]',
            ),
        );

        self::assertSame(
            ['HTTP server request count'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.server.requests")].description',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")]',
            ),
        );
    }

    public function testViewPreservesOriginalNameAndDescriptionWhenOmitted(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    aggregation:
                      last_value:
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createGauge(
                        'requests',
                        '1',
                        'Original request description',
                    )
                    ->record(42);
            },
        );

        $payload = $this->metrics[0];

        // Other metrics may be exported by installed auto-instrumentation,
        // so only inspect the metric selected by this test.
        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")]',
        );

        self::assertCount(1, $metrics);

        self::assertSame(
            ['requests'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].name',
            ),
        );

        self::assertSame(
            ['Original request description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].description',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].gauge',
            ),
        );

        self::assertSame(
            '42',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].gauge.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testViewCanOverrideDescriptionWhilePreservingOriginalName(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    description: Overridden request description
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter(
                        'requests',
                        '1',
                        'Original request description',
                    )
                    ->add(1);
            },
        );

        $payload = $this->metrics[0];

        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")]',
        );

        self::assertCount(1, $metrics);

        self::assertSame(
            ['requests'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].name',
            ),
        );

        self::assertSame(
            ['Overridden request description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")].description',
            ),
        );
    }

    public function testViewCanOverrideNameWhilePreservingOriginalDescription(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    name: http.requests
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter(
                        'requests',
                        '1',
                        'Original request description',
                    )
                    ->add(1);
            },
        );

        $payload = $this->metrics[0];

        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.requests")]',
        );

        self::assertCount(1, $metrics);

        self::assertSame(
            ['http.requests'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.requests")].name',
            ),
        );

        self::assertSame(
            ['Original request description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "http.requests")].description',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests")]',
            ),
        );
    }

    public function testInstrumentWithoutMatchingViewRemainsUnchanged(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: selected
                  stream:
                    name: selected.renamed
                    description: Selected description
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter(
                        'selected',
                        '1',
                        'Selected original description',
                    )
                    ->add(5);

                $meter
                    ->createCounter(
                        'unselected',
                        '1',
                        'Unselected original description',
                    )
                    ->add(7);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.renamed")]',
            ),
        );

        self::assertSame(
            ['Selected description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "selected.renamed")].description',
            ),
        );

        // The instrument that doesn't match the View is still exported
        // with its original name and description.
        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "unselected")]',
            ),
        );

        self::assertSame(
            ['Unselected original description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "unselected")].description',
            ),
        );

        self::assertSame(
            '7',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "unselected")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testMultipleMatchingViewsProduceMultipleStreams(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream:
                    name: requests.total
                    attribute_keys:
                      excluded:
                        - http.method

                - selector:
                    instrument_name: requests
                  stream:
                    name: requests.by_method
                    attribute_keys:
                      included:
                        - http.method
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('requests');

                $counter->add(1, ['http.method' => 'GET']);
                $counter->add(2, ['http.method' => 'POST']);
            },
        );

        $payload = $this->metrics[0];

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.total")]',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.by_method")]',
            ),
        );

        self::assertSame(
            '3',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.total")].sum.dataPoints[*].asInt',
            )[0],
        );

        self::assertCount(
            2,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.by_method")].sum.dataPoints[*]',
            ),
        );

        self::assertSame(
            ['GET', 'POST'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "requests.by_method")].sum.dataPoints[*].attributes[?(@.key == "http.method")].value.stringValue',
            ),
        );
    }

    public function testMultipleMatchingViewsApplyTheirConfigurationsIndependently(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: view.independent.requests
                  stream:
                    name: view.independent.sum
                    aggregation:
                      sum:

                - selector:
                    instrument_name: view.independent.requests
                  stream:
                    name: view.independent.by_method
                    attribute_keys:
                      included:
                        - http.method
            YAML,
            static function (): void {
                $counter = Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('view.independent.requests');

                $counter->add(
                    1,
                    [
                        'http.method' => 'GET',
                        'http.route' => '/users',
                    ],
                );

                $counter->add(
                    2,
                    [
                        'http.method' => 'POST',
                        'http.route' => '/users',
                    ],
                );
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.sum")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.by_method")]',
            ),
        );

        // The first View only changes aggregation, so its stream retains both
        // original attributes and therefore has two distinct data points.
        $sumDataPoints = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.sum")].sum.dataPoints[*]',
        );

        self::assertCount(2, $sumDataPoints);

        $sumKeys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.sum")].sum.dataPoints[*].attributes[*].key',
        );

        self::assertContains('http.method', $sumKeys);
        self::assertContains('http.route', $sumKeys);

        // The second View only retains http.method.
        $filteredKeys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.by_method")].sum.dataPoints[*].attributes[*].key',
        );

        self::assertContains('http.method', $filteredKeys);
        self::assertNotContains('http.route', $filteredKeys);

        $methods = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.by_method")].sum.dataPoints[*].attributes[?(@.key == "http.method")].value.stringValue',
        );

        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);

        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.independent.by_method")].sum.dataPoints[*].asInt',
        );

        self::assertContains('1', $values);
        self::assertContains('2', $values);
    }

    public function testViewInstrumentNameSupportsWildcardPatterns(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: "requests.*"
                  stream:
                    name: renamed.wildcard
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('requests.total')
                    ->add(1);

                $meter
                    ->createCounter('other.metric')
                    ->add(2);
            },
        );

        $payload = $this->metrics[0];

        /*
         * The wildcard pattern matched the instrument and renamed it...
         */
        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "renamed.wildcard")]',
            ),
        );

        /*
         * ...while the non-matching instrument remains unchanged.
         */
        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "other.metric")]',
            ),
        );
    }

    public function testMatchAllDropViewCanBeUsedAsDefaultWithSpecificView(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: requests
                  stream: {}

                - selector:
                    instrument_name: "*"
                  stream:
                    aggregation:
                      drop:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $meter
                    ->createCounter('requests')
                    ->add(1);

                $meter
                    ->createCounter('other')
                    ->add(1);
            },
        );

        $payload = $this->metrics[0];

        self::assertSame(
            ['requests'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[*].name',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "other")]',
            ),
        );
    }

    public function testViewIsAppliedBeforeMultipleMetricReadersExport(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              views:
                - selector:
                    instrument_name: view.multiple.readers
                  stream:
                    name: view.multiple.readers.selected
                    description: View transformed metric
                    attribute_keys:
                      included:
                        - http.method

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('view.multiple.readers')
                    ->add(
                        7,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/users',
                        ],
                    );

            },
        );

        // There should be one export from each reader.
        self::assertCount(2, $this->metrics);

        foreach ($this->metrics as $payload) {
            self::assertCount(
                1,
                $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.multiple.readers.selected")]',
                ),
            );

            self::assertSame(
                ['View transformed metric'],
                $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.multiple.readers.selected")].description',
                ),
            );

            self::assertSame(
                ['http.method'],
                $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.multiple.readers.selected")].sum.dataPoints[*].attributes[*].key',
                ),
            );

            self::assertSame(
                ['7'],
                $this->path(
                    $payload,
                    '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "view.multiple.readers.selected")].sum.dataPoints[*].asInt',
                ),
            );
        }
    }

    #[Group('view'), Group('aggregation')]
    public function testViewIgnoresAggregationIncompatibleWithInstrumentKind(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: gauge
                  stream:
                    aggregation:
                      sum:
            YAML,
            static function (): void {
                $gauge = Globals::meterProvider()
                    ->getMeter('test')
                    ->createGauge('gauge');

                $gauge->record(5);
                $gauge->record(7);
            },
        );

        self::assertNotEmpty($this->metrics);

        /*
         * A sum aggregation is incompatible with a gauge and is ignored:
         * the instrument keeps its native last-value aggregation.
         */
        self::assertSame(
            ['7'],
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "gauge")].gauge.dataPoints[*].asInt',
            ),
        );
    }

    public function testViewUsesBase2ExponentialBucketHistogramAggregation(): void {
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

              views:
                - selector:
                    instrument_name: b2.view.histogram
                  stream:
                    aggregation:
                      base2_exponential_bucket_histogram:
                        max_scale: 5
                        max_size: 32
                        record_min_max: false
            YAML, static function (): void {
            $histogram = Globals::meterProvider()->getMeter('config-test')
                ->createHistogram('b2.view.histogram');

            foreach ([1, 2, 4] as $value) {
                $histogram->record($value);
            }
        });

        /*
         * The view switches the instrument to base-2 exponential
         * aggregation; with record_min_max disabled no min/max are
         * reported.
         */
        $dataPoint = $this->path(
            $this->metrics[0],
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "b2.view.histogram")].exponentialHistogram.dataPoints[0]',
        )[0];

        self::assertArrayNotHasKey('min', $dataPoint);
        self::assertArrayNotHasKey('max', $dataPoint);
        self::assertSame(
            3,
            array_sum(array_map('intval', $dataPoint['positive']['bucketCounts'])),
        );
    }
}

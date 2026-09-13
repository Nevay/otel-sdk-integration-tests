<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\TestCase;

final class ConfigComposableViewsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Composable views
     * =========================================================================
     */

    public function testComposableViewsWithSameNameProduceOneComposedStream(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.requests
                  stream:
                    name: composable.requests.total
                    description: First description
                    aggregation:
                      sum:

                - selector:
                    instrument_name: composable.requests
                  stream:
                    name: composable.requests.total
                    description: Second description
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter(
                        'composable.requests',
                        '1',
                        'Original description',
                    )
                    ->add(5);
            },
        );

        $payload = $this->metrics[0];

        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.requests.total")]',
        );

        self::assertCount(1, $metrics);

        // The second View wins for properties other than attribute_keys.
        self::assertSame(
            ['Second description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.requests.total")].description',
            ),
        );

        // The first View selected Sum and the second View did not override it.
        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.requests.total")].sum',
            ),
        );

        self::assertSame(
            '5',
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.requests.total")].sum.dataPoints[*].asInt',
            )[0],
        );
    }

    public function testComposableViewsUseLastMatchingStreamConfiguration(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.last_wins
                  stream:
                    name: composable.last_wins
                    description: First description
                    aggregation:
                      sum:

                - selector:
                    instrument_name: composable.last_wins
                  stream:
                    name: composable.last_wins
                    description: Second description
                    aggregation:
                      sum:
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('composable.last_wins')
                    ->add(7);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.last_wins")]',
            ),
        );

        self::assertSame(
            ['Second description'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.last_wins")].description',
            ),
        );

        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.last_wins")].sum',
            ),
        );
    }

    public function testComposableViewsMergeAttributeKeysUsingIntersection(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.attributes
                  stream:
                    name: composable.attributes
                    attribute_keys:
                      included:
                        - http.method
                        - http.route

                - selector:
                    instrument_name: composable.attributes
                  stream:
                    name: composable.attributes
                    attribute_keys:
                      included:
                        - http.method
                        - http.status_code
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('composable.attributes')
                    ->add(
                        1,
                        [
                            'http.method' => 'GET',
                            'http.route' => '/users',
                            'http.status_code' => '200',
                            'server.address' => 'example.test',
                        ],
                    );
            },
        );

        $payload = $this->metrics[0];

        $keys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.attributes")].sum.dataPoints[*].attributes[*].key',
        );

        // Only http.method is included by both Views.
        self::assertContains('http.method', $keys);
        self::assertNotContains('http.route', $keys);
        self::assertNotContains('http.status_code', $keys);
        self::assertNotContains('server.address', $keys);
    }

    public function testComposableUnnamedViewJoinsNamedStreamGroup(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.named_group
                  stream:
                    name: composable.named
                    aggregation:
                      sum:

                - selector:
                    instrument_name: composable.named_group
                  stream:
                    description: Description from unnamed View
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('composable.named_group')
                    ->add(11);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.named")]',
            ),
        );

        self::assertSame(
            ['Description from unnamed View'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.named")].description',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.named_group")]',
            ),
        );
    }

    public function testComposableViewsWithDifferentNamesProduceSeparateStreams(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.different_names
                  stream:
                    name: composable.first
                    description: First stream

                - selector:
                    instrument_name: composable.different_names
                  stream:
                    name: composable.second
                    description: Second stream
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('composable.different_names')
                    ->add(13);
            },
        );

        $payload = $this->metrics[0];

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.first")]',
            ),
        );

        self::assertCount(
            1,
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.second")]',
            ),
        );

        self::assertSame(
            ['First stream'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.first")].description',
            ),
        );

        self::assertSame(
            ['Second stream'],
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.second")].description',
            ),
        );
    }

    public function testComposableViewsApplyMatchingViewsInOrder(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              view_matching_mode/development: composable

              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

              views:
                - selector:
                    instrument_name: composable.ordered
                  stream:
                    name: composable.ordered
                    aggregation:
                      sum:

                - selector:
                    instrument_name: composable.ordered
                  stream:
                    attribute_keys:
                      included:
                        - http.method

                - selector:
                    instrument_name: composable.ordered
                  stream:
                    aggregation:
                      last_value:
            YAML,
            static function (): void {
                $meter = Globals::meterProvider()->getMeter('config-test');

                $gauge = $meter->createGauge('composable.ordered');

                $gauge->record(
                    10,
                    [
                        'http.method' => 'GET',
                        'http.route' => '/users',
                    ],
                );

                $gauge->record(
                    20,
                    [
                        'http.method' => 'POST',
                        'http.route' => '/users',
                    ],
                );
            },
        );

        $payload = $this->metrics[0];

        $metrics = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")]',
        );

        self::assertCount(1, $metrics);

        // The third View overrides the aggregation selected by the first View.
        self::assertNotEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].gauge',
            ),
        );

        self::assertEmpty(
            $this->path(
                $payload,
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].sum',
            ),
        );

        // The second View's attribute configuration is retained.
        $keys = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].gauge.dataPoints[*].attributes[*].key',
        );

        self::assertContains('http.method', $keys);
        self::assertNotContains('http.route', $keys);

        // Last Value retains the most recently recorded value for each
        // remaining attribute set.
        $methods = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].gauge.dataPoints[*].attributes[?(@.key == "http.method")].value.stringValue',
        );

        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);

        $values = $this->path(
            $payload,
            '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "composable.ordered")].gauge.dataPoints[*].asInt',
        );

        self::assertContains('10', $values);
        self::assertContains('20', $values);
    }
}

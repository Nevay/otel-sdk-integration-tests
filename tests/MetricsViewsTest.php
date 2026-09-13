<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use function Amp\delay;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('config-file'), Group('metrics')]
final class MetricsViewsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Views
     * =========================================================================
     */

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

    #[Group('async')]
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

    #[Group('async')]
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

<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use function Amp\delay;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('config-file'), Group('metrics')]
final class MetricsTemporalityTest extends TestCase {
    use OTelEndpointTrait;

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
}

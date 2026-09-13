<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use function Amp\delay;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

    #[Group('config-file'), Group('metrics')]
final class MetricsCardinalityLimitTest extends TestCase {
    use OTelEndpointTrait;

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
}

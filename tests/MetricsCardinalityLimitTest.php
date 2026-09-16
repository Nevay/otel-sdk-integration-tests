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

    #[Group('async')]
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

    #[Group('async')]
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

}

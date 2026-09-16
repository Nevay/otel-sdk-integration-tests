<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

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

    #[Group('prometheus')]
    public function testPrometheusReaderServesMetricsOnConfiguredPort(): void {
        $port = 39465;

        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
            YAML,
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
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * The counter is exposed in the Prometheus text format: dots are
         * translated to underscores, the unit is inserted before the _total
         * suffix, and the meter name becomes a scope label.
         */
        self::assertStringContainsString('# TYPE test_counter_requests_total counter', $exposition);
        self::assertStringContainsString('test_counter_requests_total{otel_scope_name="prom-test"} 42', $exposition);

        /*
         * Metrics no longer go through an OTLP metrics endpoint.
         */
        self::assertSame([], $this->metrics);
    }

    #[Group('prometheus')]
    public function testPrometheusReaderInfoMetricsCanBeDisabled(): void {
        $port = 39467;

        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: prom-options

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
                        scope_info_enabled: false
                        target_info_enabled/development: false
            YAML,
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-opt')
                    ->createCounter('opt.counter', 'requests')
                    ->add(7);

                /*
                 * The Prometheus exporter starts its HTTP server during
                 * SDK initialization, which completes before this closure
                 * runs, so a single scrape is sufficient.
                 */
                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $response = $client->request($request);

                echo (string) $response->getBody();
            },
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        self::assertStringContainsString('# TYPE opt_counter_requests_total counter', $exposition);
        self::assertStringContainsString('opt_counter_requests_total 7', $exposition);

        /*
         * With scope_info_enabled: false the otel_scope_name label is absent,
         * and with target_info disabled no target_info metric is exposed even
         * though resource attributes are set.
         */
        self::assertStringNotContainsString('otel_scope_name', $exposition);
        self::assertStringNotContainsString('target_info', $exposition);

        self::assertSame([], $this->metrics);
    }

    #[Group('metrics'), Group('prometheus')]
    public function testPrometheusResourceConstantLabelsAreIncluded(): void {
        $port = 39468;

        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
                        resource_constant_labels:
                          included: [service.name]
            YAML,
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter('test.counter', 'requests')
                    ->add(42);

                /*
                 * The Prometheus exporter starts its HTTP server during
                 * SDK initialization, which completes before this closure
                 * runs, so a single scrape is sufficient.
                 */
                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $response = $client->request($request);

                echo (string) $response->getBody();
            },
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * The listed resource attribute is added as a constant label to
         * every metric series, next to the scope label.
         */
        self::assertMatchesRegularExpression(
            '/test_counter_requests_total\{[^}]*service_name="unknown_service:php"[^}]*\} 42/',
            $exposition,
        );
    }

    #[Group('metrics'), Group('prometheus')]
    public function testPrometheusResourceConstantLabelsAreExcluded(): void {
        $port = 39470;

        /*
         * A resource attribute listed in both included and excluded is not
         * exported as a constant label; one that is only included still is.
         */
        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: custom.label
                  value: custom-value

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
                        resource_constant_labels:
                          included: [service.name, custom.label]
                          excluded: [custom.label]
            YAML,
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter('test.counter', 'requests')
                    ->add(42);

                /*
                 * The Prometheus exporter starts its HTTP server during
                 * SDK initialization, which completes before this closure
                 * runs, so a single scrape is sufficient.
                 */
                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $response = $client->request($request);

                echo (string) $response->getBody();
            },
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * The counter carries the included resource attribute (service.name)
         * as a constant label, but not the excluded one (custom.label).
         */
        self::assertMatchesRegularExpression(
            '/test_counter_requests_total\{otel_scope_name="prom-test",service_name="unknown_service:php"\} 42/',
            $exposition,
        );
    }

    #[Group('metrics'), Group('prometheus')]
    public function testPrometheusTranslationStrategyWithoutSuffixes(): void {
        $port = 39469;

        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
                        translation_strategy: no_translation/development
            YAML,
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter('test.counter', 'requests')
                    ->add(42);

                /*
                 * The Prometheus exporter starts its HTTP server during
                 * SDK initialization, which completes before this closure
                 * runs, so a single scrape is sufficient.
                 */
                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $response = $client->request($request);

                echo (string) $response->getBody();
            },
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * Without suffixes the metric keeps its plain translated name:
         * no unit segment, no _total suffix.
         */
        self::assertStringContainsString('# TYPE test_counter counter', $exposition);
        self::assertStringNotContainsString('_requests_total', $exposition);
    }

    /*
     * =========================================================================
     * Prometheus escaping schemes (content negotiation)
     * =========================================================================
     */

    #[Group('metrics'), Group('prometheus')]
    public function testPrometheusEscapingUnderscoresReplacesNonLegacyCharacters(): void {
        $port = 39470;

        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
                        translation_strategy: no_translation/development
            YAML,
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter("a\xC3\xA9.b\xE2\x82\xAC_x")
                    ->add(1, ["l\xC3\xA9bel" => 'v']);

                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $request->setHeader('accept', 'text/plain;version=1.0.0;charset=utf-8;escaping=underscores');

                echo (string) $client->request($request)->getBody();
            },
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * The underscores scheme replaces each character outside the legacy
         * character set with a single underscore: the two-byte é and the
         * three-byte € are each consumed as one character and collapse to a
         * single underscore, and consecutive non-legacy characters (dot,
         * underscore) merge into the same one. Label names are escaped with
         * the same scheme.
         */
        self::assertStringContainsString('# TYPE a_b_x counter', $exposition);
        self::assertStringContainsString('a_b_x{l_bel="v",otel_scope_name="prom-test"} 1', $exposition);
    }

    #[Group('metrics'), Group('prometheus')]
    public function testPrometheusEscapingDotsSchemeEscapesDotsAndUnderscores(): void {
        $port = 39471;

        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
                        translation_strategy: no_translation/development
            YAML,
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter("a\xC3\xA9.b\xE2\x82\xAC_x")
                    ->add(1, ["l\xC3\xA9bel" => 'v']);

                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $request->setHeader('accept', 'text/plain;version=1.0.0;charset=utf-8;escaping=dots');

                echo (string) $client->request($request)->getBody();
            },
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * The dots scheme replaces dots with _dot_, existing underscores with
         * double underscores, and other non-legacy characters (é, €) with a
         * single underscore. No collapsing happens, so the dot following é
         * yields adjacent underscores. The scheme applies to label names as
         * well, including the reserved scope labels.
         */
        self::assertStringContainsString('# TYPE a__dot_b___x counter', $exposition);
        self::assertStringContainsString('a__dot_b___x{l_bel="v",otel__scope__name="prom-test"} 1', $exposition);
    }

    #[Group('metrics'), Group('prometheus')]
    public function testPrometheusEscapingValuesSchemeEncodesCodePoints(): void {
        $port = 39472;

        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
                        translation_strategy: no_translation/development
            YAML,
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter("a\xC3\xA9.b\xE2\x82\xAC_x")
                    ->add(1, ["l\xC3\xA9bel" => 'v']);

                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $request->setHeader('accept', 'text/plain;version=1.0.0;charset=utf-8;escaping=values');

                echo (string) $client->request($request)->getBody();
            },
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * The values scheme prefixes the name with U__, encodes each
         * non-legacy character as its Unicode code point in hexadecimal
         * surrounded by underscores (é is U+00E9, dot U+002E, € U+20AC),
         * and doubles existing underscores. The spec does not mandate a hex
         * case, so the assertion is case-insensitive.
         */
        self::assertMatchesRegularExpression(
            '/# TYPE U__a_[eE]9__2[eE]_b_20[aA][cC]___x counter\n'
            . 'U__a_[eE]9__2[eE]_b_20[aA][cC]___x\{U__l_e9_bel="v",U__otel__scope__name="prom-test"\} 1/',
            $exposition,
        );
    }

    #[Group('metrics'), Group('prometheus')]
    public function testPrometheusEscapingAllowUtf8PreservesValidUtf8Names(): void {
        $port = 39473;

        $exposition = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            meter_provider:
              readers:
                - pull:
                    exporter:
                      prometheus/development:
                        host: 127.0.0.1
                        port: ${OTEL_EXPORTER_PROMETHEUS_PORT}
                        translation_strategy: no_translation/development
            YAML,
            static function () use ($port): void {
                Globals::meterProvider()
                    ->getMeter('prom-test')
                    ->createCounter("a\xC3\xA9.b\xE2\x82\xAC_x")
                    ->add(1, ["l\xC3\xA9bel" => 'v']);

                $client = HttpClientBuilder::buildDefault();
                $request = new Request('http://127.0.0.1:' . $port . '/metrics');
                $request->setHeader('accept', 'text/plain;version=1.0.0;charset=utf-8;escaping=allow-utf-8');

                echo (string) $client->request($request)->getBody();
            },
            'OTEL_EXPORTER_PROMETHEUS_PORT=' . $port,
        );

        /*
         * The allow-utf-8 scheme passes valid UTF-8 names through unaltered.
         * Names outside the legacy character set are exposed in the quoted
         * UTF-8 name form of the text format, both for metric names and for
         * label names; legacy names such as the scope label stay unquoted.
         */
        self::assertStringContainsString('# TYPE "aé.b€_x" counter', $exposition);
        self::assertStringContainsString('{"aé.b€_x","lébel"="v",otel_scope_name="prom-test"} 1', $exposition);
    }

    #[Group('metrics')]
    public function testExporterDefaultBase2ExponentialHistogramAggregation(): void {
        $this->runOTelConfig(<<<'YAML'
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
    public function testReaderCardinalityLimitBucketsOverflowSeries(): void {
        $this->runOTelConfig(<<<'YAML'
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

    public function testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow(): void {
        /*
         * OTEL_PHP_SHUTDOWN_TIMEOUT is a tbachert/otel-sdk vendor variable
         * that bounds that SDK's retry backoff after the timed-out export;
         * open-telemetry/sdk ignores it and completes its shutdown on its
         * own within a second.
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
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
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

<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('config-file'), Group('metrics'), Group('prometheus')]
final class ConfigPrometheusExporterTest extends TestCase {
    use OTelEndpointTrait;

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
}

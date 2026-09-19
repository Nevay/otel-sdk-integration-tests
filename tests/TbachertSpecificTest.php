<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vendor-specific tests for tbachert/otel-sdk: behavior beyond the
 * specification surface (vendor configuration nodes and variables, log
 * message wording) that is not part of the shared spec group.
 */
#[Group('tbachert')]
final class TbachertSpecificTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Vendor log level classification
     * =========================================================================
     */

    /**
     * The specification defines OTEL_LOG_LEVEL but not the log level of
     * individual self-diagnostic messages. This test pins down
     * tbachert/otel-sdk's classification (export failures are logged at
     * warning level, initialization errors at error level), which other SDKs
     * may legitimately classify differently.
     */
    #[Group('env'), Group('traces')]
    public function testLogLevelControlsSdkLogging(): void {
        $failEndpoint = 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . str_replace(
            '/v1/traces',
            '/v1/fail',
            $this->env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'],
        );

        $emitSpan = static function (): void {
            Globals::tracerProvider()
                ->getTracer('test')
                ->spanBuilder('log-level')
                ->startSpan()
                ->end();
        };

        /*
         * The default level (info) reports the export failure as a warning.
         */
        $this->runOTel($emitSpan, $failEndpoint);

        self::assertStringContainsString(
            'Export failure',
            $this->lastStderr,
        );

        /*
         * At level error, the warning is suppressed...
         */
        $this->runOTel($emitSpan, $failEndpoint, 'OTEL_LOG_LEVEL=error');

        self::assertStringNotContainsString(
            'Export failure',
            $this->lastStderr,
        );

        /*
         * ...but error-level messages such as the initialization failure are
         * still logged.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "9.9"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            $emitSpan,
            'OTEL_LOG_LEVEL=error',
        );

        /*
         * The SDK capitalizes the product name differently in this code
         * path ('OpenTelemetry' vs 'opentelemetry'), so compare
         * case-insensitively.
         */
        self::assertStringContainsString(
            'error during opentelemetry initialization',
            strtolower($this->lastStderr),
        );
    }

    /*
     * =========================================================================
     * OTEL_PHP_SHUTDOWN_TIMEOUT (vendor variable)
     * =========================================================================
     */

    /**
     * Vendor-specific: OTEL_PHP_SHUTDOWN_TIMEOUT is not part of the
     * specification. Without it, a timed-out export attempt triggers the
     * exporter's retry backoff sequence (five attempts with exponential
     * delays), which keeps the process alive for ~30 s during shutdown.
     * This test pins that the variable bounds the shutdown and that its
     * value is in seconds (it is passed verbatim to the async runtime's
     * timeout cancellation).
     */
    #[Group('env'), Group('traces'), Group('async')]
    public function testPhpShutdownTimeoutBoundsRetryBackoffAfterTimedOutExport(): void {
        $start = microtime(true);

        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('test')
                    ->spanBuilder('shutdown-timeout')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->baseUrl . '/v1/slow',
            'OTEL_PHP_SHUTDOWN_TIMEOUT=2',
        );

        /*
         * The export was attempted... the slow route delays its response
         * beyond the 100 ms timeout...
         */
        self::assertGreaterThanOrEqual(1, $this->slowRequests);

        /*
         * ...so the span was never delivered.
         */
        self::assertSame([], $this->traces);

        /*
         * With the 2 s cap the child process finishes well below the ~30 s
         * that the unbounded retry backoff sequence would take; if the
         * variable were ignored (or interpreted in milliseconds) this run
         * would take tens of seconds.
         */
        self::assertLessThan(15.0, microtime(true) - $start);
    }

    /*
     * =========================================================================
     * capture_code_attributes processor (vendor node)
     * =========================================================================
     */

    /**
     * Vendor-specific: capture_code_attributes/development is not part of the
     * official opentelemetry-configuration data model; it is an experimental
     * processor of tbachert/otel-sdk.
     */
    #[Group('config-file'), Group('traces')]
    public function testCaptureCodeAttributesProcessorAddsSourceLocation(): void
    {
        $run = function (string $config): array {
            $this->runOTelConfig(
                $config,
                static function (): void {
                    Globals::tracerProvider()
                        ->getTracer('config-test')
                        ->spanBuilder('code-attrs')
                        ->startSpan()
                        ->end();
                },
            );

            /*
             * Each run appends to the captured payloads; use the most
             * recent one.
             */
            $payload = end($this->traces);

            return $this->path(
                $payload,
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "code-attrs")].attributes',
            )[0];
        };

        $attributeValue = static function (array $attributes, string $key): ?array {
            foreach ($attributes as $attribute) {
                if ($attribute['key'] === $key) {
                    return $attribute['value'];
                }
            }

            return null;
        };

        /*
         * With capture_stacktrace: true the span carries source location and
         * a stack trace.
         */
        $attributes = $run(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - capture_code_attributes/development:
                    capture_stacktrace: true
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML);

        self::assertIsArray($attributeValue($attributes, 'code.file.path'));
        self::assertIsArray($attributeValue($attributes, 'code.line.number'));
        self::assertIsArray($attributeValue($attributes, 'code.function.name'));

        $stacktrace = $attributeValue($attributes, 'code.stacktrace');
        self::assertIsArray($stacktrace);
        self::assertNotSame('', $stacktrace['stringValue']);

        /*
         * Without the option the source location is still captured, but no
         * stack trace.
         */
        $attributes = $run(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - capture_code_attributes/development:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML);

        self::assertIsArray($attributeValue($attributes, 'code.file.path'));
        self::assertNull($attributeValue($attributes, 'code.stacktrace'));
    }

    /*
     * =========================================================================
     * SDK self-observability (semconv: /docs/specs/semconv/otel/)
     * =========================================================================
     */

    /**
     * The metric names defined by the semantic conventions for OpenTelemetry
     * SDK metrics (development status).
     *
     * @var list<string>
     */
    private const SEMCONV_METRICS = [
        'otel.sdk.span.live',
        'otel.sdk.span.started',
        'otel.sdk.processor.span.queue.size',
        'otel.sdk.processor.span.queue.capacity',
        'otel.sdk.processor.span.processed',
        'otel.sdk.exporter.span.inflight',
        'otel.sdk.exporter.span.exported',
        'otel.sdk.log.created',
        'otel.sdk.processor.log.queue.size',
        'otel.sdk.processor.log.queue.capacity',
        'otel.sdk.processor.log.processed',
        'otel.sdk.exporter.log.inflight',
        'otel.sdk.exporter.log.exported',
        'otel.sdk.exporter.metric_data_point.inflight',
        'otel.sdk.exporter.metric_data_point.exported',
        'otel.sdk.metric_reader.collection.duration',
        'otel.sdk.exporter.operation.duration',
    ];

    #[Group('config-file'), Group('metrics')]
    public function testSelfObservabilityMetricsCanBeEnabled(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            meter_provider:
              readers:
                - periodic:
                    interval: 60000
                    timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
              meter_configurator/development:
                meters:
                  - name: com.tobiasbachert.otel.sdk.otlpexporter
                    config:
                      enabled: true
            YAML,
            static function (): void {
                /*
                 * Self-observability instruments are only recorded while the
                 * SDK is actually exporting telemetry: ending this span
                 * triggers the exporter, which records its instruments
                 * against the SDK's own meter provider.
                 */
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('self-obs-metrics')
                    ->startSpan();
                $span->end();
            },
        );

        self::assertCount(1, $this->metrics);
        $payload = $this->metrics[0];

        /*
         * Enabling the exporter's instrumentation scope via the development
         * configurator exports its self-observability instruments. Only the
         * span was emitted, so only span-exporter metrics may appear — and
         * every exported name must be one of the semconv-defined ones.
         */
        $metrics = array_filter(
            $this->path($payload, '$.resourceMetrics[*].scopeMetrics[*].metrics[*]'),
            static fn(array $metric): bool => str_starts_with($metric['name'] ?? '', 'otel.sdk.'),
        );
        self::assertNotEmpty($metrics);

        $names = array_map(static fn(array $metric): string => $metric['name'], $metrics);
        foreach ($names as $name) {
            self::assertContains($name, self::SEMCONV_METRICS);
        }

        /*
         * The shutdown collection happens while the final span export is in
         * flight, so the semconv-defined otel.sdk.exporter.span.inflight
         * metric (an UpDownCounter of {span}) must be present: a cumulative,
         * non-monotonic sum carrying the recommended component attributes.
         */
        self::assertContains('otel.sdk.exporter.span.inflight', $names);

        $inflight = null;
        foreach ($metrics as $metric) {
            if ($metric['name'] === 'otel.sdk.exporter.span.inflight') {
                $inflight = $metric;
            }
        }
        self::assertNotNull($inflight);
        self::assertSame('{span}', $inflight['unit']);
        self::assertSame(2, $inflight['sum']['aggregationTemporality']);
        self::assertFalse($inflight['sum']['isMonotonic'] ?? false);

        $attributes = array_column($inflight['sum']['dataPoints'][0]['attributes'], 'key');
        self::assertContains('otel.component.name', $attributes);
        self::assertContains('otel.component.type', $attributes);
    }
}

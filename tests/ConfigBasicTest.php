<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('config-file')]
final class ConfigBasicTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Basic configuration
     * =========================================================================
     */

    #[Group('traces')]
    public function testConfigFileConfiguresTracerProvider(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: config-file-service

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('config-file')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        self::assertSame(
            'config-file-service',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );

        self::assertSame(
            ['config-file'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('traces')]
    public function testOtlpHttpExporterHeadersAreSentToCollector(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        headers:
                          - name: auth
                            value: config-token
                          - name: x-custom
                            value: v2
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('config-headers')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The headers configured on the otlp_http exporter are sent with
         * the export request.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);

        self::assertSame(['config-token'], $headers['auth']);
        self::assertSame(['v2'], $headers['x-custom']);
    }

    #[Group('traces')]
    public function testOtlpHttpGzipCompressionIsApplied(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        compression: gzip
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('gzip-span')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The request body is gzip-compressed and marked as such; the fake
         * collector decodes it before parsing.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['gzip'], $headers['content-encoding']);

        self::assertContains('gzip-span', $this->spanNames($this->traces[0]));
    }

    #[Group('traces')]
    public function testOtlpHttpEncodingJsonIsApplied(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        encoding: json
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('json-encoding')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The default encoding is protobuf; with 'json' the payload is sent
         * as application/json.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['application/json'], $headers['content-type']);

        self::assertContains('json-encoding', $this->spanNames($this->traces[0]));
    }

    #[Group('traces')]
    public function testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            distribution:
              tbachert/otel-sdk:
                #
                # Bound the shutdown so the test does not wait out the SDK's
                # full exponential-backoff retry sequence (~30 s).
                #
                shutdown_timeout: 1

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        timeout: 300
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('slow-collector')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . str_replace(
                '/v1/traces',
                '/v1/slow',
                $this->env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'],
            ),
        );

        /*
         * The export was attempted...
         */
        self::assertGreaterThanOrEqual(1, $this->slowRequests);

        /*
         * ...but the collector does not answer within the 300 ms timeout:
         * the payload is dropped and the process still shuts down cleanly
         * (runOTelConfig would throw on a non-zero exit code).
         */
        self::assertSame([], $this->traces);
    }

    #[Group('traces'), Group('metrics'), Group('logs')]
    public function testSignalsCanBeConfiguredIndependently(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: all-signals

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('all-signals-span')
                    ->startSpan();

                $span->end();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('all-signals.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('all-signals-log'));
            },
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);

        self::assertSame(
            'all-signals',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );

        self::assertSame(
            ['all-signals-span'],
            $this->spanNames($this->traces[0]),
        );

        self::assertNotEmpty(
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "all-signals.counter")]',
            ),
        );

        self::assertSame(
            'all-signals-log',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            )[0],
        );
    }

    /*
     * =========================================================================
     * SDK disabled
     * =========================================================================
     */

    #[Group('traces'), Group('metrics'), Group('logs')]
    public function testDisabledConfigurationDisablesAllSignals(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            disabled: true

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('disabled')
                    ->startSpan();

                $span->end();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('disabled.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('disabled'));
            },
        );

        self::assertSame([], $this->traces);
        self::assertSame([], $this->metrics);
        self::assertSame([], $this->logs);
    }

    #[Group('traces'), Group('metrics'), Group('logs')]
    public function testUnsupportedFileFormatResultsInNoOpSdk(): void {
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
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('invalid-format')
                    ->startSpan();

                $span->end();

                Globals::meterProvider()
                    ->getMeter('config-test')
                    ->createCounter('c')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('c'));
            },
        );

        /*
         * The invalid configuration is logged during initialization and the
         * SDK degrades to a no-op: nothing is exported.
         */
        self::assertSame([], $this->traces);
        self::assertSame([], $this->metrics);
        self::assertSame([], $this->logs);
    }

    #[Group('traces')]
    public function testConfigFileNewerMinorFormatIsAcceptedWithWarning(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.3"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('newer-minor-format')
                    ->startSpan();

                $span->end();
            },
        );

        /*
         * A file_format whose minor version is ahead of the implemented one
         * is still processed: the configuration applies and the span is
         * exported. (Per the data model versioning policy, a major mismatch
         * should produce an error; a newer minor should be detected and
         * warned about while remaining usable.)
         */
        self::assertCount(1, $this->traces);
        self::assertSame(
            ['newer-minor-format'],
            $this->spanNames($this->traces[0]),
        );

        /*
         * The SDK reports the version mismatch as a diagnostic mentioning
         * the file_format.
         */
        self::assertStringContainsString(
            'file_format',
            strtolower($this->lastStderr),
        );
    }

    #[Group('traces')]
    /*
     * Vendor-specific: capture_code_attributes/development is not part of the
     * official opentelemetry-configuration data model; it is an experimental
     * processor of tbachert/otel-sdk.
     */
    #[Group('tbachert')]
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

    #[Group('traces')]
    public function testOtlpHttpMaxRequestSizeBlocksOversizedExports(): void {
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        max_request_size: 1
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('config-test')
                ->spanBuilder('oversized')
                ->startSpan()
                ->end();
        });

        /*
         * The payload is rejected by the exporter before it reaches the
         * collector; the failure is reported as a warning.
         */
        self::assertSame([], $this->traces);
        self::assertStringContainsString(
            'maximum request size',
            strtolower($this->lastStderr),
        );
    }

    #[Group('traces')]
    public function testLogLevelErrorSuppressesSdkWarnings(): void {
        $failEndpoint = str_replace('/v1/traces', '/v1/fail', $this->baseUrl . '/v1/traces');

        $emitSpan = static function (): void {
            Globals::tracerProvider()->getTracer('config-test')
                ->spanBuilder('log-level')
                ->startSpan()
                ->end();
        };

        /*
         * The default level (info) reports the export failure as a warning.
         */
        $this->runOTelConfig(<<<YAML
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: {$failEndpoint}
        YAML, $emitSpan);

        self::assertStringContainsString('Export failure', $this->lastStderr);

        /*
         * At level error the warning is suppressed.
         */
        $this->runOTelConfig(<<<YAML
            file_format: "1.2"
            log_level: error

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: {$failEndpoint}
        YAML, $emitSpan);

        self::assertStringNotContainsString('Export failure', $this->lastStderr);
    }

    #[Group('traces')]
    public function testOtlpHttpMaxResponseSizeRejectsLargeResponses(): void {
        /*
         * Unlike max_request_size, the request is sent; the exporter only
         * rejects the collector's response body once it exceeds the
         * configured limit. (JSON encoding is used so that the empty
         * response body is larger than one byte.)
         */
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        encoding: json
                        max_response_size: 1
        YAML, static function (): void {
            Globals::tracerProvider()->getTracer('config-test')
                ->spanBuilder('oversized-response')
                ->startSpan()
                ->end();
        });

        self::assertStringContainsString(
            'buffer length limit',
            strtolower($this->lastStderr),
        );
    }

    #[Group('traces')]
    public function testOtlpFileExporterWritesNewlineDelimitedJson(): void {
        $file = tempnam(sys_get_temp_dir(), 'otlp-file');

        try {
            $this->runOTelConfig(<<<YAML
                file_format: "1.2"

                tracer_provider:
                  processors:
                    - batch:
                        exporter:
                          otlp_file/development:
                            output_stream: {$file}
            YAML, static function (): void {
                Globals::tracerProvider()->getTracer('config-test')
                    ->spanBuilder('file-export')
                    ->startSpan()
                    ->end();
            });

            $payloads = array_values(array_filter(
                explode("\n", (string) file_get_contents($file)),
            ));

            self::assertCount(1, $payloads);

            /*
             * Each line is a standalone OTLP/JSON export; json_decode
             * doubles as the well-formedness check.
             */
            json_decode($payloads[0], true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(
                ['file-export'],
                $this->path(
                    $payloads[0],
                    '$.resourceSpans[*].scopeSpans[*].spans[*].name',
                ),
            );
        } finally {
            @unlink($file);
        }
    }
}

<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
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

    /*
     * =========================================================================
     * Span export fidelity
     * =========================================================================
     *
     * A tracer provider configured via the config file must export spans with
     * full detail (status, events, kinds, links, typed attributes), like the
     * env-configured pipeline.
     */

    #[Group('traces')]
    public function testSpansExportedWithStatusEventsKindsAndLinks(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        encoding: json
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                $error = $tracer->spanBuilder('status-error')->startSpan();
                $error->setStatus(StatusCode::STATUS_ERROR, 'boom');
                $error->end();

                $span = $tracer->spanBuilder('details-span')
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->addLink(SpanContext::create(
                        '11111111111111111111111111111111',
                        '1111111111111111',
                        TraceFlags::SAMPLED,
                    ))
                    ->startSpan();

                $span->setAttribute('int.value', 42);
                $span->addEvent('first-event', ['event.attr' => 'ev']);

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        $spans = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[*]',
        );

        $byName = array_column($spans, null, 'name');

        /*
         * OTLP status codes: 2 = error; span kinds: 2 = server.
         */
        self::assertSame(['message' => 'boom', 'code' => 2], $byName['status-error']['status']);
        self::assertSame(2, $byName['details-span']['kind']);

        self::assertSame(
            [['key' => 'int.value', 'value' => ['intValue' => '42']]],
            $byName['details-span']['attributes'],
        );
        self::assertSame(
            [['key' => 'event.attr', 'value' => ['stringValue' => 'ev']]],
            $byName['details-span']['events'][0]['attributes'],
        );
        self::assertSame(
            '11111111111111111111111111111111',
            $byName['details-span']['links'][0]['traceId'],
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
        /*
         * The timed-out attempt is retryable, so without an upper bound the
         * exporter would keep retrying with exponential backoff for tens of
         * seconds. export_timeout bounds the whole export call (the
         * spec-compliant processor-level limit) so the test stays fast.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    export_timeout: 600
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
        /*
         * A far-future minor of the current major version: this stays
         * "newer than implemented" without having to be bumped whenever a
         * new schema minor is released (a major mismatch is covered by
         * testUnsupportedFileFormatResultsInNoOpSdk).
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.99"

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
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
        YAML, $emitSpan, 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $failEndpoint);

        self::assertStringContainsString('Export failure', $this->lastStderr);

        /*
         * At level error the warning is suppressed.
         */
        $this->runOTelConfig(<<<'YAML'
            file_format: "1.2"
            log_level: error

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
        YAML, $emitSpan, 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $failEndpoint);

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

    #[Group('traces')]
    public function testConsoleExporterWritesSpansToStdout(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      console:
            YAML,
            static function (): void {
                Globals::tracerProvider()->getTracer('config-test')
                    ->spanBuilder('console-span')
                    ->startSpan()
                    ->end();
            },
        );

        /*
         * The specification leaves the console exporter's output format
         * unspecified ("can vary between implementations"), so we only pin
         * down that the span reaches stdout and does not go to the OTLP
         * HTTP collector.
         */
        self::assertStringContainsString('console-span', $output);
        self::assertSame([], $this->traces);
    }

    #[Group('traces')]
    public function testIdGeneratorRandomProducesSpecConformIds(): void {
        /*
         * The random id generator is the default; configuring it explicitly
         * must produce 128-bit trace ids and 64-bit span ids.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              id_generator:
                random:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                Globals::tracerProvider()->getTracer('config-test')
                    ->spanBuilder('id-generator')
                    ->startSpan()
                    ->end();
            },
        );

        self::assertNotEmpty($this->traces);

        $traceId = $this->normalizeId(
            (string) $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "id-generator")].traceId',
            )[0],
        );

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);

        $spanId = $this->normalizeId(
            (string) $this->path(
                $this->traces[0],
                '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "id-generator")].spanId',
            )[0],
        );

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spanId);
    }

    public function testMissingConfigFileFailsInitialization(): void {
        /*
         * A configuration file that does not exist is an initialization
         * error: the SDK reports it and degrades to a no-op.
         */
        $this->runOTelExpectingInitError(
            static function (): void {
                Globals::tracerProvider()->getTracer('config-test')
                    ->spanBuilder('missing-config')
                    ->startSpan()
                    ->end();
            },
            'OTEL_CONFIG_FILE=/nonexistent/otel-test-config.yaml',
        );

        self::assertSame([], $this->traces);
    }

    /**
     * When OTEL_CONFIG_FILE is set, all other environment variables besides
     * those referenced in the configuration file for substitution MUST be
     * ignored: a hostile environment (console exporter, always_off sampler,
     * disabled SDK, extra resource attributes) must not change the
     * file-configured behavior.
     */
    public function testConfigFileModeIgnoresOtherEnvironmentVariables(): void {
        $this->runOTelConfig(
            <<<YAML
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: env-ignore-service

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: {$this->baseUrl}/v1/traces
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('env-ignore')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_EXPORTER=console',
            'OTEL_TRACES_SAMPLER=always_off',
            'OTEL_SDK_DISABLED=true',
            'OTEL_RESOURCE_ATTRIBUTES=env.leak=yes',
        );

        /*
         * The file-configured pipeline still exports: the console exporter,
         * always_off sampler and disabled SDK from the environment had no
         * effect.
         */
        self::assertNotEmpty($this->traces);

        self::assertSame(
            'env-ignore-service',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );

        /*
         * OTEL_RESOURCE_ATTRIBUTES from the environment must not leak into
         * the file-configured resource.
         */
        self::assertSame(
            [],
            $this->path(
                $this->traces[0],
                '$.resourceSpans[*].resource.attributes[?(@.key == "env.leak")].value.*',
            ),
        );
    }
}

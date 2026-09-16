<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('env')]
final class OtlpExporterTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Protocol
     * =========================================================================
     */

    public function testOtlpHttpProtobufProtocolExportsAllSignals(): void {
        /*
         * http/protobuf is the SDK's default wire format; the test harness
         * otherwise forces http/json. All three signals must export over
         * protobuf.
         */
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('proto.span')
                    ->startSpan();

                $span->setAttribute('transport', 'protobuf');
                $span->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('proto.counter')
                    ->add(7);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('proto-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=http/protobuf',
            'OTEL_EXPORTER_OTLP_METRICS_PROTOCOL=http/protobuf',
            'OTEL_EXPORTER_OTLP_LOGS_PROTOCOL=http/protobuf',
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);

        $span = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "proto.span")]',
        );

        self::assertCount(1, $span);
        self::assertSame(
            ['key' => 'transport', 'value' => ['stringValue' => 'protobuf']],
            $span[0]['attributes'][0],
        );

        /*
         * The collector re-serializes protobuf messages to JSON, so byte
         * fields appear as base64 of the raw 16/8 byte ids.
         */
        self::assertSame(
            16,
            strlen(base64_decode($span[0]['traceId'])),
        );

        self::assertSame(
            8,
            strlen(base64_decode($span[0]['spanId'])),
        );

        self::assertSame(
            ['7'],
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "proto.counter")].sum.dataPoints[*].asInt',
            ),
        );

        self::assertSame(
            ['proto-log'],
            $this->path($this->logs[0], '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue'),
        );
    }

    public function testGenericOtlpProtocolAppliesToAllSignals(): void {
        /*
         * The signal-agnostic protocol variable selects the wire format for
         * every signal at once (the harness default is http/json).
         */
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('generic-proto.span')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('generic-proto.counter')
                    ->add(7);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('generic-proto-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf',
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);

        /*
         * The collector re-serializes protobuf messages to JSON, so byte
         * fields appear as base64 of the raw 16/8 byte ids.
         */
        $span = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "generic-proto.span")]',
        );

        self::assertCount(1, $span);
        self::assertSame(16, strlen(base64_decode($span[0]['traceId'])));
        self::assertSame(8, strlen(base64_decode($span[0]['spanId'])));

        self::assertSame(
            ['7'],
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "generic-proto.counter")].sum.dataPoints[*].asInt',
            ),
        );

        self::assertSame(
            ['generic-proto-log'],
            $this->path($this->logs[0], '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue'),
        );
    }
    /*
     * =========================================================================
     * Headers
     * =========================================================================
     */

    public function testGenericOtlpHeadersApplyToAllSignals(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('generic-headers-span')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('generic-headers.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('generic-headers-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_HEADERS=auth=secret-token,x-custom=v1',
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);

        /*
         * The signal-agnostic headers are sent with every export, whatever
         * the signal (exports may arrive in any order, so check them all).
         * Header values are multi-value lists.
         */
        self::assertCount(3, $this->requestHeaders);

        foreach ($this->requestHeaders as $headers) {
            $headers = array_change_key_case($headers);

            self::assertSame(['secret-token'], $headers['auth']);
            self::assertSame(['v1'], $headers['x-custom']);
        }
    }
    #[Group('traces')]
    public function testPerSignalOtlpHeadersReplaceGenericHeaders(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('header-precedence')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_HEADERS=generic=g1',
            'OTEL_EXPORTER_OTLP_TRACES_HEADERS=specific=s1',
        );

        self::assertNotEmpty($this->traces);

        $headers = array_change_key_case($this->requestHeaders[0]);

        /*
         * The per-signal variable takes precedence over the generic one:
         * only the specific header is sent, not both.
         */
        self::assertSame(['s1'], $headers['specific']);
        self::assertArrayNotHasKey('generic', $headers);
    }
    #[Group('metrics')]
    public function testPerSignalOtlpHeadersApplyToMetrics(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('per-signal-headers.counter')
                    ->add(1);
            },
            'OTEL_EXPORTER_OTLP_METRICS_HEADERS=specific=m1',
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);

        self::assertSame(['m1'], $headers['specific']);
    }
    #[Group('logs')]
    public function testPerSignalOtlpHeadersApplyToLogs(): void {
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('per-signal-headers-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_LOGS_HEADERS=specific=l1',
        );

        self::assertNotEmpty($this->logs);

        $headers = array_change_key_case($this->requestHeaders[0]);

        self::assertSame(['l1'], $headers['specific']);
    }
    #[Group('traces')]
    public function testOtlpUserAgentHeaderIdentifiesExporterLanguageAndVersion(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('user-agent-span')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The OTLP exporter specification states that exporters SHOULD
         * emit a User-Agent header identifying at minimum the exporter,
         * the language of its implementation, and the version.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertArrayHasKey('user-agent', $headers);
        self::assertCount(1, $headers['user-agent']);
        /*
         * The version component may be a composer dev identifier (e.g.
         * "dev-main"), so only its presence is asserted.
         */
        self::assertMatchesRegularExpression(
            '/otlp.*php.*\/\S+/i',
            $headers['user-agent'][0],
        );
    }
    /*
     * =========================================================================
     * Compression
     * =========================================================================
     */

    public function testGenericOtlpCompressionAppliesToAllSignals(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('generic-gzip-span')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('generic-gzip.counter')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('generic-gzip-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_COMPRESSION=gzip',
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);

        /*
         * The signal-agnostic compression is applied to every export, so the
         * fake collector decodes each gzip-compressed body before parsing.
         */
        self::assertCount(3, $this->requestHeaders);

        foreach ($this->requestHeaders as $headers) {
            self::assertSame(
                ['gzip'],
                array_change_key_case($headers)['content-encoding'],
            );
        }
    }
    #[Group('metrics')]
    public function testPerSignalOtlpCompressionAppliesToMetrics(): void {
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('per-signal-gzip.counter')
                    ->add(1);
            },
            'OTEL_EXPORTER_OTLP_METRICS_COMPRESSION=gzip',
        );

        self::assertNotEmpty($this->metrics);

        $headers = array_change_key_case($this->requestHeaders[0]);

        self::assertSame(['gzip'], $headers['content-encoding']);
    }
    #[Group('logs')]
    public function testPerSignalOtlpCompressionAppliesToLogs(): void {
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('per-signal-gzip-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_LOGS_COMPRESSION=gzip',
        );

        self::assertNotEmpty($this->logs);

        $headers = array_change_key_case($this->requestHeaders[0]);

        self::assertSame(['gzip'], $headers['content-encoding']);
    }
    /*
     * =========================================================================
     * Endpoint resolution
     * =========================================================================
     */

    public function testGenericOtlpEndpointIsUsedWithAppendedSignalPaths(): void
    {
        /*
         * Blank the per-signal endpoints (empty behaves as unset) so that
         * only the generic OTEL_EXPORTER_OTLP_ENDPOINT applies.
         */
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('generic-endpoint')
                    ->startSpan()
                    ->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('generic.counter', 'requests')
                    ->add(1);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('generic-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=',
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=',
            'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=',
            'OTEL_EXPORTER_OTLP_ENDPOINT=' . $this->baseUrl,
        );

        /*
         * Per the specification, the signal paths are appended to the
         * generic endpoint: all three signals reach the same base URL.
         */
        self::assertContains(
            'generic-endpoint',
            $this->spanNames($this->traces[0]),
        );
        self::assertSame(
            ['1'],
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "generic.counter")].sum.dataPoints[*].asInt',
            ),
        );
        self::assertSame(
            ['generic-log'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }
    #[Group('traces')]
    public function testPerSignalEndpointWithoutPathSendsToRoot(): void
    {
        /*
         * Per the OTLP specification, a per-signal endpoint without a path
         * part is used as-is (root path), unlike the generic endpoint which
         * gets /v1/<signal> appended.
         */
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('per-signal-root')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->baseUrl,
        );

        self::assertContains(
            'per-signal-root',
            $this->spanNames($this->traces[0]),
        );
    }
    #[Group('traces')]
    public function testSignalSpecificOtlpEndpointTakesPrecedenceOverGeneric(): void
    {
        /*
         * The per-signal traces endpoint (set by the harness) must win over
         * the generic one, even though the latter is set to an unroutable
         * address.
         */
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('precedence')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:9',
        );

        self::assertContains(
            'precedence',
            $this->spanNames($this->traces[0]),
        );
    }
    /*
     * =========================================================================
     * Retry and throttling
     * =========================================================================
     */
    #[Group('traces')]
    public function testCollectorRejectingExportDoesNotBreakShutdown(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('rejected')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . str_replace(
                '/v1/traces',
                '/v1/fail',
                $this->env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'],
            ),
        );

        /*
         * The collector rejects the export with a non-retryable status. Per
         * the OTLP specification, all 4xx/5xx codes other than 429, 502,
         * 503 and 504 MUST NOT be retried: exactly one request was made.
         */
        self::assertSame(1, $this->failRequests);

        /*
         * The failure is logged and swallowed: the process exits cleanly
         * (runOTel would throw on a non-zero exit code) and nothing was
         * captured by the real endpoint.
         */
        self::assertSame([], $this->traces);
    }

    public function testOtlpHttpRetriesRetryableStatusCodes(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('retried')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . str_replace(
                '/v1/traces',
                '/v1/flaky',
                $this->env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'],
            ),
        );

        /*
         * The first attempt receives a retryable 503; per the OTLP
         * specification the exporter retries with backoff, and the second
         * attempt delivers the data.
         */
        self::assertSame(2, $this->flakyRequests);
        self::assertCount(1, $this->traces);
        self::assertSame(
            ['retried'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testOtlpHttpHonorsThrottlingResponse(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('throttled')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . str_replace(
                '/v1/traces',
                '/v1/throttle',
                $this->env['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'],
            ),
        );

        /*
         * The first attempt receives a 429 with a Retry-After header of six
         * seconds; per the OTLP specification the client honours it, so the
         * retry happens well after the maximum backoff delay (five seconds).
         */
        self::assertSame(2, $this->throttleRequests);
        self::assertGreaterThanOrEqual(
            5.5,
            $this->requestTimes[1] - $this->requestTimes[0],
        );

        /*
         * The second attempt delivers the data.
         */
        self::assertCount(1, $this->traces);
        self::assertSame(
            ['throttled'],
            $this->spanNames($this->traces[0]),
        );
    }
    /*
     * =========================================================================
     * Timeout
     * =========================================================================
     */

    public function testGenericOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow(): void {
        /*
         * OTEL_EXPORTER_OTLP_TIMEOUT bounds every OTLP export attempt in
         * milliseconds. The /v1/slow route answers 500 ms after the request,
         * beyond the 100 ms timeout, so the export is cancelled and the span
         * dropped.
         *
         * OTEL_PHP_SHUTDOWN_TIMEOUT is a tbachert/otel-sdk vendor variable
         * that bounds that SDK's retry backoff after the timed-out export;
         * open-telemetry/sdk ignores it and completes its shutdown on its
         * own within a second.
         */
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('test')
                    ->spanBuilder('timeout-generic')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->baseUrl . '/v1/slow',
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
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
    }

    public function testTracesOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow(): void {
        /*
         * The per-signal OTEL_EXPORTER_OTLP_TRACES_TIMEOUT bounds each trace
         * export attempt in milliseconds (the signal-agnostic variable is
         * covered above). Same setup, so a collector slower than the bound
         * drops the span.
         */
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()->getTracer('test')
                    ->spanBuilder('otlp-timeout-traces')
                    ->startSpan()
                    ->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=' . $this->baseUrl . '/v1/slow',
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
        );

        self::assertGreaterThanOrEqual(1, $this->slowRequests);
        self::assertSame([], $this->traces);
    }

    public function testMetricExportTimeoutEnvVarDropsExportWhenCollectorIsSlow(): void {
        /*
         * The retry backoff after a timed-out export would otherwise keep
         * the process alive for tens of seconds; bound the shutdown with the
         * vendor-specific timeout so the test stays fast.
         */
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('timeout.counter')
                    ->add(1);
            },
            'OTEL_METRIC_EXPORT_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=' . str_replace(
                '/v1/metrics',
                '/v1/slow',
                $this->env['OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'],
            ),
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
        );

        /*
         * The export was attempted... the slow route delays its response
         * beyond the 100 ms export timeout...
         */
        self::assertGreaterThanOrEqual(1, $this->slowRequests);

        /*
         * ...so the data point was never delivered.
         */
        self::assertSame([], $this->metrics);
    }

    public function testMetricsOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow(): void {
        /*
         * The per-signal OTEL_EXPORTER_OTLP_METRICS_TIMEOUT bounds each
         * metrics export attempt in milliseconds (the signal-agnostic
         * variable is covered by EnvEdgeCasesTest). The /v1/slow route
         * answers 500 ms after the request, beyond the 100 ms timeout, so
         * the export is cancelled and the data point dropped.
         *
         * OTEL_PHP_SHUTDOWN_TIMEOUT is a tbachert/otel-sdk vendor variable
         * that bounds that SDK's retry backoff after the timed-out export;
         * open-telemetry/sdk ignores it and completes its shutdown on its
         * own within a second.
         */
        $this->runOTel(
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('otlp-timeout.counter')
                    ->add(1);
            },
            'OTEL_EXPORTER_OTLP_METRICS_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT=' . $this->baseUrl . '/v1/slow',
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
        );

        self::assertGreaterThanOrEqual(1, $this->slowRequests);
        self::assertSame([], $this->metrics);
    }

    public function testLogsOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow(): void {
        /*
         * The per-signal OTEL_EXPORTER_OTLP_LOGS_TIMEOUT bounds each log
         * export attempt in milliseconds (the signal-agnostic variable is
         * covered by EnvEdgeCasesTest). Same setup as the BLRP timeout test
         * above, so a collector slower than the bound drops the record.
         */
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('otlp-timeout-log'));
            },
            'OTEL_EXPORTER_OTLP_LOGS_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=' . $this->baseUrl . '/v1/slow',
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
        );

        self::assertGreaterThanOrEqual(1, $this->slowRequests);
        self::assertSame([], $this->logs);
    }

    /**
     * An empty OTEL_TRACES_EXPORTER must be treated as unset, so the
     * default (otlp) exporter is used and the span is exported.
     */
    public function testEmptyTracesExporterEnvironmentVariableFallsBackToOtlp(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_EXPORTER=',
        );

        self::assertNotEmpty($this->traces);
    }

    /**
     * Enum environment variables SHOULD be interpreted in a case-insensitive
     * manner: OTEL_TRACES_EXPORTER=OTLP must select the OTLP exporter, so
     * the span is exported.
     * 
     * open-telemetry/sdk currently matches exporter names case-sensitively
     * and fails initialization for unrecognized values.
     */
    public function testTracesExporterEnvironmentVariableIsCaseInsensitive(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_EXPORTER=OTLP',
        );

        self::assertNotEmpty($this->traces);
    }

    /**
     * An unrecognized OTEL_EXPORTER_OTLP_PROTOCOL must be warned about and
     * gracefully ignored, so the default transport (http/protobuf) is used.
     * 
     * Both SDKs currently fail initialization instead.
     */
    public function testUnrecognizedProtocolFallsBackToDefault(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_PROTOCOL=carrier-pigeon',
        );

        self::assertNotEmpty($this->traces);
        self::assertContains(
            'application/x-protobuf',
            array_column($this->requestHeaders, 'content-type'),
        );
    }

    /**
     * OTEL_EXPORTER_OTLP_HEADERS uses the W3C baggage format, so values are
     * percent encoded: x-probe=a%20b must arrive as "a b".
     */
    public function testExporterHeadersDecodePercentEncodedValues(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_TRACES_HEADERS=x-probe=a%20b',
        );

        self::assertNotEmpty($this->traces);
        $values = $this->requestHeaders[0]['x-probe'] ?? [];
        if (is_array($values)) {
            $values = $values[0] ?? null;
        }

        self::assertSame('a b', $values);
    }
}

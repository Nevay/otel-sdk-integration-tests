<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('env'), Group('traces')]
final class EnvEdgeCasesTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Edge cases
     * =========================================================================
     */

    public function testEmptyEnvironmentVariableBehavesAsUnset(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('empty-env')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=',
        );

        self::assertNotEmpty($this->traces);
    }

    public function testInvalidSamplerDoesNotPreventSdkStartup(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('invalid-sampler')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=not-a-sampler',
        );

        self::assertNotEmpty($this->traces);
    }

    public function testInvalidSamplerArgumentDoesNotPreventSdkStartup(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('invalid-argument')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=not-a-number',
        );

        self::assertNotEmpty($this->traces);
    }

    /**
     * @param list<string> $expected
     */
    public function testMalformedResourceAttributesAreIgnored(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('malformed-attrs')
                    ->startSpan();

                $span->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=not-a-pair,good.key=good-value',
        );

        self::assertNotEmpty($this->traces);

        /*
         * The entry without a "=" separator is dropped...
         */
        $keys = $this->path(
            $this->combinedTracePayload(),
            '$.resourceSpans[*].resource.attributes[*].key',
        );

        self::assertNotContains('not-a-pair', $keys);

        /*
         * ...while the valid entry in the same list is applied.
         */
        self::assertSame(
            'good-value',
            $this->resourceAttribute($this->combinedTracePayload(), 'good.key'),
        );
    }

    public function testUnknownPropagatorDisablesPropagationWithoutBreakingStartup(): void {
        $output = $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('unknown-propagator')
                    ->startSpan();

                $span->end();

                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $carrier = [];
                Globals::propagator()->inject($carrier, null, $context);

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
            'OTEL_PROPAGATORS=doesnotexist',
        );

        /*
         * The SDK still starts and exports spans...
         */
        self::assertNotEmpty($this->traces);

        /*
         * ...but the unknown propagator results in no propagation at all.
         */
        self::assertSame(
            [],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

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

    #[Group('async')]
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

    #[Group('async')]
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

    #[Group('metrics'), Group('logs')]
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

    #[Group('async')]
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

    #[Group('async')]
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
}

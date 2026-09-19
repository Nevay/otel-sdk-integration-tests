<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Closure;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('env'), Group('traces')]
final class EnvSamplingTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Sampling
     * =========================================================================
     */

    public function testAlwaysOnSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('always-on')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=always_on',
        );

        $this->assertSpanNames(['always-on']);
    }

    public function testAlwaysOffSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('always-off')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=always_off',
        );

        self::assertSame([], $this->traces);
    }

    public function testTraceIdRatioSamplerZero(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('ratio-zero')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=0',
        );

        self::assertSame([], $this->traces);
    }

    public function testTraceIdRatioSamplerOne(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('ratio-one')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=1',
        );

        $this->assertSpanNames(['ratio-one']);
    }

    public function testParentBasedAlwaysOnSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('parent-based-on')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=parentbased_always_on',
        );

        $this->assertSpanNames(['parent-based-on']);
    }

    public function testParentBasedAlwaysOffSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('parent-based-off')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=parentbased_always_off',
        );

        self::assertSame([], $this->traces);
    }

    public function testParentBasedTraceIdRatioSampler(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('parent-based-ratio')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=parentbased_traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=1',
        );

        $this->assertSpanNames(['parent-based-ratio']);
    }

    public function testParentBasedTraceIdRatioZeroDropsRootButKeepsSampledRemoteChild(): void {
        $this->runOTel(
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                // Root span: ratio 0 -> dropped.
                $root = $tracer->spanBuilder('dropped-root')->startSpan();
                $root->end();

                // Child of a sampled remote parent: parent decision wins.
                $remoteParent = SpanContext::create(
                    '4193e569320548f7b71d4c5a750d504c',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED,
                );

                $child = $tracer
                    ->spanBuilder('kept-child')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($remoteParent)))
                    ->startSpan();
                $child->end();
            },
            'OTEL_TRACES_SAMPLER=parentbased_traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=0',
        );

        self::assertSpanNames(['kept-child']);
    }

    #[Group('async'), Group('jaeger')]
    public function testJaegerRemoteSamplerDialsPlaintextEndpoint(): void {
        $this->assertPlaintextGrpcDial(
            static fn (int $port): array => [
                'OTEL_TRACES_SAMPLER' => 'jaeger_remote',
                'OTEL_TRACES_SAMPLER_ARG' => 'endpoint=http://127.0.0.1:' . $port . ',pollingIntervalMs=50,initialSamplingRate=0',
            ],
            static function (): void {
                /*
                 * Keep the event loop alive past the first 50 ms poll tick;
                 * no span is needed — the sampler polls on its own timer.
                 */
                \Amp\delay(0.5);
            },
        );
    }

    /*
     * =========================================================================
     * Jaeger remote sampling strategies (full round trip against an in-process
     * h2c gRPC peer serving the Jaeger remote sampling API)
     * =========================================================================
     */

    #[Group('async'), Group('jaeger')]
    public function testJaegerRemoteProbabilityStrategyIsApplied(): void {
        $this->runJaegerRemoteSampling(
            JaegerSamplingServer::probabilityStrategy(1.0),
            static function (): void {
                // Wait past the first successful poll (~50 ms).
                \Amp\delay(0.3);

                /*
                 * The initial sampling rate is 0, so this span can only be
                 * exported if the remote PROBABILITY strategy (rate 1.0) was
                 * fetched and applied.
                 */
                Globals::tracerProvider()->getTracer('test')
                    ->spanBuilder('remote-sampled')
                    ->startSpan()
                    ->end();
            },
        );

        self::assertSpanNames(['remote-sampled']);
    }

    #[Group('async'), Group('jaeger')]
    public function testJaegerRemotePerOperationStrategyMatchesSpanNames(): void {
        $this->runJaegerRemoteSampling(
            JaegerSamplingServer::operationsStrategy(0.0, ['kept' => 1.0]),
            static function (): void {
                \Amp\delay(0.3);

                foreach (['kept', 'dropped'] as $name) {
                    Globals::tracerProvider()->getTracer('test')
                        ->spanBuilder($name)
                        ->startSpan()
                        ->end();
                }
            },
        );

        /*
         * The OPERATIONS strategy matches per operation (span name): 'kept'
         * is sampled at rate 1.0, 'dropped' falls back to the default of 0.
         */
        self::assertSpanNames(['kept']);
    }

    #[Group('async'), Group('jaeger')]
    public function testJaegerRemoteRateLimitingStrategyLimitsSpans(): void {
        /*
         * maxTracesPerSecond=1 gives the token bucket a cost of one second per
         * trace and a capacity of one, with the debit starting at the moment
         * the strategy is applied (t_c). The first span must come at least one
         * second after t_c to be in budget; once it is sampled, the debit sits
         * exactly at its arrival time, so the immediately following span is
         * dropped and the third needs another full second. (t_c lags closure
         * start by only the first poll tick — ~50 ms plus 50 ms per failed
         * poll — regardless of how slow initialization is, which bounds the
         * required margins.)
         */
        $this->runJaegerRemoteSampling(
            JaegerSamplingServer::rateLimitingStrategy(1),
            static function (): void {
                \Amp\delay(1.3);

                Globals::tracerProvider()->getTracer('test')
                    ->spanBuilder('rl-first')
                    ->startSpan()
                    ->end();
                Globals::tracerProvider()->getTracer('test')
                    ->spanBuilder('rl-second')
                    ->startSpan()
                    ->end();

                // One more second: the bucket must refill and sampling resumes.
                \Amp\delay(1.05);

                Globals::tracerProvider()->getTracer('test')
                    ->spanBuilder('rl-third')
                    ->startSpan()
                    ->end();
            },
        );

        self::assertSpanNames(['rl-first', 'rl-third']);
    }

    #[Group('async'), Group('jaeger')]
    public function testJaegerRemoteInitialSamplerAppliesWhileBackendUnreachable(): void {
        $this->runOTel(
            static function (): void {
                // Let a few polls fail against the closed port.
                \Amp\delay(0.3);

                /*
                 * initialSamplingRate=1: while the backend cannot be reached,
                 * the initial sampler keeps sampling everything.
                 */
                Globals::tracerProvider()->getTracer('test')
                    ->spanBuilder('initial-sampled')
                    ->startSpan()
                    ->end();
            },
            'OTEL_TRACES_SAMPLER=jaeger_remote',
            'OTEL_TRACES_SAMPLER_ARG=endpoint=http://127.0.0.1:1,pollingIntervalMs=50,initialSamplingRate=1',
        );

        self::assertSpanNames(['initial-sampled']);
    }

    private function runJaegerRemoteSampling(string $strategy, Closure $telemetry): void {
        $server = new JaegerSamplingServer($strategy);
        $port = $server->start();

        try {
            $this->runOTel(
                $telemetry,
                'OTEL_TRACES_SAMPLER=jaeger_remote',
                'OTEL_TRACES_SAMPLER_ARG=endpoint=http://127.0.0.1:' . $port . ',pollingIntervalMs=50,initialSamplingRate=0',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * An empty OTEL_TRACES_SAMPLER must be treated as unset, so the default
     * sampler (parentbased_always_on) samples root spans.
     */
    public function testEmptySamplerEnvironmentVariableFallsBackToDefault(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=',
        );

        self::assertNotEmpty($this->traces);
    }

    /**
     * Enum environment variables SHOULD be interpreted in a case-insensitive
     * manner: an uppercase OTEL_TRACES_SAMPLER value must select the same
     * sampler as its lowercase spelling, so root spans are sampled.
     *
     * open-telemetry/sdk currently matches sampler names case-sensitively
     * and fails initialization for unrecognized values.
     */
    public function testSamplerEnvironmentVariableIsCaseInsensitive(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('upper-sampler')
                    ->startSpan();

                $span->end();
            },
            'OTEL_TRACES_SAMPLER=PARENTBASED_ALWAYS_ON',
        );

        self::assertNotEmpty($this->traces);
    }

    /**
     * OTEL_TRACES_SAMPLER_ARG: invalid or unrecognized input must be logged
     * and otherwise ignored, i.e., the implementation must behave as if the
     * variable were not set. For traceidratio the default sampling
     * probability is 1.0, so all root spans are sampled.
     *
     * open-telemetry/sdk currently fails initialization on a non-numeric
     * value instead of ignoring it.
     */
    public function testInvalidSamplerArgumentFallsBackToDefaultSampling(): void
    {
        $this->runOTel(
            static function (): void {
                for ($i = 0; $i < 25; $i++) {
                    Globals::tracerProvider()
                        ->getTracer('test')
                        ->spanBuilder("ratio-{$i}")
                        ->startSpan()
                        ->end();
                }
            },
            'OTEL_TRACES_SAMPLER=traceidratio',
            'OTEL_TRACES_SAMPLER_ARG=bogus',
        );

        $spans = 0;
        foreach ($this->traces as $payload) {
            foreach ((array) \json_decode($payload, true)['resourceSpans'] ?? [] as $resourceSpan) {
                foreach ($resourceSpan['scopeSpans'][0]['spans'] ?? [] as $span) {
                    $spans++;
                }
            }
        }

        self::assertSame(25, $spans);
    }
}

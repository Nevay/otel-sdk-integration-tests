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
}

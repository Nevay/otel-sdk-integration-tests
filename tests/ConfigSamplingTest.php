<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

    #[Group('config-file'), Group('traces')]
final class ConfigSamplingTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Sampling
     * =========================================================================
     */

    public function testAlwaysOnSampler(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                always_on:

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('always-on')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertSame(
            ['always-on'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testAlwaysOffSampler(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                always_off:

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('always-off')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertSame([], $this->traces);
    }

    public function testTraceIdRatioBasedSampler(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                trace_id_ratio_based:
                  ratio: 1.0

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('ratio-one')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertSame(
            ['ratio-one'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testTraceIdRatioBasedSamplerZero(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                trace_id_ratio_based:
                  ratio: 0.0

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('ratio-zero')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertSame([], $this->traces);
    }

    public function testParentBasedSampler(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                parent_based:
                  root:
                    always_on:

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('parent-based')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertSame(
            ['parent-based'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testParentBasedSamplerRootAlwaysOff(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                parent_based:
                  root:
                    always_off:

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('parent-based-off')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertSame([], $this->traces);
    }

    public function testParentBasedSamplerRootRatioZeroStillSamplesRemoteChild(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                parent_based:
                  root:
                    trace_id_ratio_based:
                      ratio: 0
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

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
        );

        self::assertSame(
            ['kept-child'],
            $this->spanNames($this->traces[0]),
        );

        /*
         * The child inherits the remote parent's trace id. Depending on how
         * the span context was constructed, the payload carries it as a hex
         * string or as OTLP-JSON base64.
         */
        $childTraceId = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "kept-child")].traceId',
        )[0];

        self::assertContainsEquals(
            '4193e569320548f7b71d4c5a750d504c',
            [
                $childTraceId,
                bin2hex(base64_decode($childTraceId, true) ?? ''),
            ],
        );
    }

    #[Group('sampler')]
    public function testTraceIdRatioBasedSamplerSamplesOnlyAFractionOfSpans(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                trace_id_ratio_based:
                  ratio: 0.5

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('test');

                for ($i = 0; $i < 100; $i++) {
                    $tracer
                        ->spanBuilder('fraction')
                        ->startSpan()
                        ->end();
                }
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * A ratio between the boundaries samples some, but not all, spans.
         */
        $count = count($this->spanNames($this->traces[0]));

        self::assertNotSame(0, $count);
        self::assertNotSame(100, $count);
    }
}

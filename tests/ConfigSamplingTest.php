<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
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
         * The child inherits the remote parent's trace id. The captured
         * payload carries it as a hex string (native OTLP/JSON, per the
         * OTLP specification) or as base64 (OTLP/protobuf re-serialized by
         * the harness with the canonical proto3 JSON mapping); see the
         * note on id encoding in OTelEndpointTrait::captureRequestBody().
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
    public function testRuleBasedSamplerRoutesBySpanKindAndAttributes(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  rule_based:
                    rules:
                      - span_kinds: [client]
                        sampler:
                          always_on:
                      - attribute_values:
                          key: db.system
                          values: [mysql]
                        sampler:
                          always_off:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                /* Matches rule 1 (span kind) -> sampled. */
                $client = $tracer->spanBuilder('rule-client')
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->startSpan();
                $client->end();

                /* Matches rule 2 (attribute value) -> dropped. */
                $mysql = $tracer->spanBuilder('rule-mysql')
                    ->setAttribute('db.system', 'mysql')
                    ->startSpan();
                $mysql->end();

                /* Matches no rule -> dropped. */
                $plain = $tracer->spanBuilder('rule-plain')->startSpan();
                $plain->end();
            },
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['rule-client'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testParentThresholdSamplerFollowsSampledRemoteParent(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  parent_threshold:
                    root:
                      always_off:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                /* Root span: the root sampler (always_off) applies. */
                $root = $tracer->spanBuilder('pt-root')->startSpan();
                $root->end();

                /* Child of a sampled remote parent: recorded despite the
                 * always_off root sampler. */
                $remoteParent = SpanContext::create(
                    '4193e569320548f7b71d4c5a750d504c',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED,
                );

                $child = $tracer
                    ->spanBuilder('pt-child')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($remoteParent)))
                    ->startSpan();
                $child->end();
            },
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['pt-child'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testAlwaysRecordSamplerRecordsWithoutReporting(): void
    {
        $out = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                always_record:
                  root:
                    always_off:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('always-record')
                    ->startSpan();

                /*
                 * The delegate sampler drops the span, but always_record
                 * downgrades the decision to record-only: the span still
                 * records attributes and events.
                 */
                echo 'RECORDING=' . var_export($span->isRecording(), true) . "\n";

                $span->setAttribute('kept', 'yes');
                $span->addEvent('an-event');
                $span->end();
            },
        );

        self::assertStringContainsString('RECORDING=true', $out);

        /* Record-only spans are not reported to the exporter. */
        self::assertSame([], $this->traces);
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

    #[Group('sampler')]
    public function testRuleBasedSamplerMatchesAttributePatterns(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  rule_based:
                    rules:
                      - attribute_patterns:
                          key: component
                          included: [http-*]
                        sampler:
                          always_on:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                /* Matches the wildcard pattern -> sampled. */
                $http = $tracer->spanBuilder('rule-http')
                    ->setAttribute('component', 'http-client')
                    ->startSpan();
                $http->end();

                /* Does not match the pattern -> dropped. */
                $grpc = $tracer->spanBuilder('rule-grpc')
                    ->setAttribute('component', 'grpc-client')
                    ->startSpan();
                $grpc->end();
            },
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['rule-http'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testComposableProbabilitySampler(): void {
        $emitSpan = static function (): void {
            $tracer = Globals::tracerProvider()->getTracer('config-test');

            $span = $tracer->spanBuilder('probability')->startSpan();
            $span->end();
        };

        /* A ratio of zero drops every span... */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  probability:
                    ratio: 0
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            $emitSpan,
        );

        self::assertSame([], $this->traces);

        /* ...while a ratio of one keeps every span. */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  probability:
                    ratio: 1
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            $emitSpan,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['probability'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testProbabilitySamplerWritesThresholdTraceState(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                probability/development:
                  ratio: 1.0
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('prob-th')
                    ->startSpan();

                self::emitSpanContext($span->getContext());
                $span->end();
            },
        );

        /*
         * The non-composable probability sampler is a consistent-probability
         * sampler: it encodes its rejection threshold in the OpenTelemetry
         * TraceState `th` sub-key (a ratio of one keeps every span, so the
         * threshold is zero) and sets the W3C Trace Context Level 2 random
         * flag on generated trace IDs. The explicit `rv` randomness value is
         * only inserted when the trace ID does not carry the random flag,
         * which cannot be configured through environment variables or the
         * configuration file.
         */
        $context = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ot=th:0', $context['tracestate']);
        self::assertSame(
            TraceFlags::SAMPLED | TraceFlags::RANDOM,
            $context['flags'],
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['prob-th'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testProbabilitySamplerThresholdMatchesSamplingDecisions(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                probability/development:
                  ratio: 0.5
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                for ($i = 0; $i < 40; $i++) {
                    $span = $tracer->spanBuilder('prob-decision')->startSpan();
                    self::emitSpanContext($span->getContext());
                    $span->end();
                }
            },
        );

        /*
         * A ratio of one half maps to the rejection threshold 2**55, encoded
         * as `th:8` (trailing zeros are stripped). The threshold is written
         * for sampled decisions only — dropped spans carry no ot entry — so
         * that downstream participants can reproduce the same decision; each
         * decision must match the comparison of the trace ID's rightmost 56
         * bits of randomness with the threshold.
         */
        $lines = array_values(array_filter(explode("\n", trim($output))));

        self::assertCount(40, $lines);

        foreach ($lines as $line) {
            $context = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            $randomness = hexdec(substr($context['trace_id'], -14));
            $sampled = ($context['flags'] & TraceFlags::SAMPLED) !== 0;

            self::assertSame(
                $randomness >= 0x80000000000000,
                $sampled,
                sprintf('Decision for %s does not match the threshold.', $context['trace_id']),
            );

            /* The threshold accompanies sampled decisions only. */
            self::assertSame($sampled ? 'th:8' : null, $context['ot']);
        }
    }

    #[Group('sampler')]
    public function testComposableProbabilitySamplerWritesThresholdTraceState(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  probability:
                    ratio: 1.0
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('prob-composable')
                    ->startSpan();

                self::emitSpanContext($span->getContext());
                $span->end();
            },
        );

        /* The composable form is wrapped in the composite sampler, which
         * performs the same consistent-probability bookkeeping. */
        $context = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ot=th:0', $context['tracestate']);
        self::assertSame(
            TraceFlags::SAMPLED | TraceFlags::RANDOM,
            $context['flags'],
        );
    }

    #[Group('sampler')]
    public function testProbabilitySamplerTraceStatePropagatesToChildSpans(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                probability/development:
                  ratio: 1.0
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                $root = $tracer->spanBuilder('prob-parent')->startSpan();
                $child = $tracer
                    ->spanBuilder('prob-child')
                    ->setParent($root->storeInContext(Context::getCurrent()))
                    ->startSpan();

                self::emitSpanContext($root->getContext());
                self::emitSpanContext($child->getContext());

                $child->end();
                $root->end();
            },
        );

        /*
         * The threshold (and any explicit randomness value) propagates through
         * span contexts unmodified, so that child samplers can make the same
         * decision as their parent.
         */
        [$parent, $child] = array_values(array_filter(explode("\n", trim($output))));
        $parentContext = json_decode($parent, true, 512, JSON_THROW_ON_ERROR);
        $childContext = json_decode($child, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ot=th:0', $parentContext['tracestate']);
        self::assertSame($parentContext['tracestate'], $childContext['tracestate']);
        self::assertSame($parentContext['trace_id'], $childContext['trace_id']);

        /* The child ends first, so it is exported first. */
        self::assertCount(1, $this->traces);
        self::assertSame(
            ['prob-child', 'prob-parent'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testProbabilitySamplerPreservesPropagatedExplicitRandomness(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                probability/development:
                  ratio: 0.5
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                /*
                 * A remote parent whose trace ID's rightmost 56 bits fall
                 * below the threshold (0x1d4c... < 0x8000...) but which
                 * carries an explicit randomness value above it.
                 */
                $remoteParent = SpanContext::createFromRemoteParent(
                    '4193e569320548f7b71d4c5a750d504c',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED | TraceFlags::RANDOM,
                    (new TraceState())->with('ot', 'rv:fd70a400000000'),
                );

                $child = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('rv-child')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($remoteParent)))
                    ->startSpan();

                self::emitSpanContext($child->getContext());
                $child->end();
            },
        );

        /*
         * The explicit randomness value takes precedence over the trace ID's
         * own bits as the source of randomness - the decision is positive
         * here, while the trace ID alone would have been dropped - and SDKs
         * and samplers must not overwrite it; the sampler adds its threshold
         * alongside.
         */
        $context = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('4193e569320548f7b71d4c5a750d504c', $context['trace_id']);
        self::assertSame(TraceFlags::SAMPLED, $context['flags'] & TraceFlags::SAMPLED);

        $entries = array_flip(explode(';', (string) $context['ot']));

        self::assertArrayHasKey('rv:fd70a400000000', $entries);
        self::assertArrayHasKey('th:8', $entries);

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['rv-child'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testComposableProbabilitySamplerUsesPropagatedExplicitRandomness(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  probability:
                    ratio: 0.5
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                /*
                 * Two remote parents whose trace IDs' rightmost 56 bits point
                 * in opposite directions (0x1d4c... below, 0xf5e4... above
                 * the threshold), while their explicit randomness values
                 * point in the opposite direction again.
                 */
                $kept = SpanContext::createFromRemoteParent(
                    '4193e569320548f7b71d4c5a750d504c',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED | TraceFlags::RANDOM,
                    (new TraceState())->with('ot', 'rv:fd70a400000000'),
                );

                $dropped = SpanContext::createFromRemoteParent(
                    'f7e6d5c4b3a29180a8f5e4d3c2b1a098',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED | TraceFlags::RANDOM,
                    (new TraceState())->with('ot', 'rv:0123456789abcd'),
                );

                $tracer = Globals::tracerProvider()->getTracer('config-test');

                $keptChild = $tracer
                    ->spanBuilder('prob-rv-kept')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($kept)))
                    ->startSpan();

                self::emitSpanContext($keptChild->getContext());
                $keptChild->end();

                $droppedChild = $tracer
                    ->spanBuilder('prob-rv-dropped')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($dropped)))
                    ->startSpan();

                self::emitSpanContext($droppedChild->getContext());
                $droppedChild->end();
            },
        );

        /*
         * The composable form (wrapped in the composite sampler) makes both
         * decisions from the explicit randomness value, not from the trace
         * ID's own bits: the span whose randomness is above the threshold is
         * sampled despite its trace ID, and the one below it is dropped
         * despite its trace ID. The randomness values are preserved in both
         * outcomes (MUST NOT be modified); the threshold accompanies the
         * sampled decision only, as the spec's CompositeSampler section
         * requires removing `th` for negative decisions.
         */
        [$keptLine, $droppedLine] = array_values(array_filter(explode("\n", trim($output))));
        $keptContext = json_decode($keptLine, true, 512, JSON_THROW_ON_ERROR);
        $droppedContext = json_decode($droppedLine, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('4193e569320548f7b71d4c5a750d504c', $keptContext['trace_id']);
        self::assertSame(TraceFlags::SAMPLED, $keptContext['flags'] & TraceFlags::SAMPLED);

        $entries = array_flip(explode(';', (string) $keptContext['ot']));

        self::assertArrayHasKey('rv:fd70a400000000', $entries);
        self::assertArrayHasKey('th:8', $entries);

        self::assertSame('f7e6d5c4b3a29180a8f5e4d3c2b1a098', $droppedContext['trace_id']);
        self::assertSame(0, $droppedContext['flags'] & TraceFlags::SAMPLED);

        /*
         * Negative decision: the randomness value is preserved and no
         * threshold may be written, so the ot entry stays as received.
         */
        self::assertSame('rv:0123456789abcd', $droppedContext['ot']);

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['prob-rv-kept'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testComposableProbabilitySamplerConsistentAcrossThresholds(): void {
        $makeSpan = static function (): void {
            /*
             * A remote parent whose trace ID's rightmost 56 bits fall below
             * both thresholds (0x1d4c...), while its explicit randomness
             * value sits between them: above the threshold of a ratio of one
             * half (2**55) and below the threshold of a ratio of one quarter
             * (3*2**54).
             */
            $remoteParent = SpanContext::createFromRemoteParent(
                '4193e569320548f7b71d4c5a750d504c',
                '6e0c63258deeeff4',
                TraceFlags::SAMPLED | TraceFlags::RANDOM,
                (new TraceState())->with('ot', 'rv:a0000000000000'),
            );

            $child = Globals::tracerProvider()
                ->getTracer('config-test')
                ->spanBuilder('prob-threshold')
                ->setParent(Context::getCurrent()->withContextValue(Span::wrap($remoteParent)))
                ->startSpan();

            self::emitSpanContext($child->getContext());
            $child->end();
        };

        /* A participant with the looser threshold keeps the span... */
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  probability:
                    ratio: 0.5
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            $makeSpan,
        );

        $context = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(TraceFlags::SAMPLED, $context['flags'] & TraceFlags::SAMPLED);

        $entries = array_flip(explode(';', (string) $context['ot']));

        self::assertArrayHasKey('rv:a0000000000000', $entries);
        self::assertArrayHasKey('th:8', $entries);

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['prob-threshold'],
            $this->spanNames($this->traces[0]),
        );

        /* ...while a participant with the stricter threshold drops it.
         * Both decisions derive from the same explicit randomness value,
         * which is what makes probability sampling consistent across
         * participants with different thresholds. */
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  probability:
                    ratio: 0.25
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            $makeSpan,
        );

        $context = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $context['flags'] & TraceFlags::SAMPLED);

        /*
         * Negative decision: the randomness value is preserved and no
         * threshold may be written, so the ot entry stays as received.
         */
        self::assertSame('rv:a0000000000000', $context['ot']);

        /* The stricter participant exported nothing. */
        self::assertCount(1, $this->traces);
    }

    #[Group('sampler')]
    public function testParentThresholdSamplerInheritsRemoteParentThreshold(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                composite/development:
                  parent_threshold:
                    root:
                      probability:
                        ratio: 0.5
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $tracer = Globals::tracerProvider()->getTracer('config-test');

                /*
                 * A consistent sampled remote parent: its threshold (0xc0...)
                 * is satisfied by the trace ID's rightmost 56 bits (0xd0...),
                 * so the child follows the parent and inherits its threshold
                 * instead of the local sampler's.
                 */
                $above = SpanContext::createFromRemoteParent(
                    '4193e569320548f7b7d0a8f5e4d3c2b1',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED | TraceFlags::RANDOM,
                    (new TraceState())->with('ot', 'th:c'),
                );

                $child = $tracer
                    ->spanBuilder('pt-above')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($above)))
                    ->startSpan();

                self::emitSpanContext($child->getContext());
                $child->end();

                /*
                 * An inconsistent parent: sampled, but with a threshold that
                 * its own trace ID could not have satisfied (0xc0... >
                 * 0xa0...). The threshold must be ignored and the decision
                 * fall back to the parent's sampled flag.
                 */
                $mismatch = SpanContext::createFromRemoteParent(
                    '4193e569320548f7b7a0a8f5e4d3c2b1',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED | TraceFlags::RANDOM,
                    (new TraceState())->with('ot', 'th:c'),
                );

                $child = $tracer
                    ->spanBuilder('pt-mismatch')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($mismatch)))
                    ->startSpan();

                self::emitSpanContext($child->getContext());
                $child->end();

                /* A dropped remote parent without a threshold: dropped. */
                $dropped = SpanContext::createFromRemoteParent(
                    '4193e569320548f7b7d0a8f5e4d3c2b1',
                    '6e0c63258deeeff4',
                );

                $child = $tracer
                    ->spanBuilder('pt-parent-dropped')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($dropped)))
                    ->startSpan();

                self::emitSpanContext($child->getContext());
                $child->end();
            },
        );

        [$aboveLine, $mismatchLine, $droppedLine] = array_values(array_filter(explode("\n", trim($output))));
        $aboveContext = json_decode($aboveLine, true, 512, JSON_THROW_ON_ERROR);
        $mismatchContext = json_decode($mismatchLine, true, 512, JSON_THROW_ON_ERROR);
        $droppedContext = json_decode($droppedLine, true, 512, JSON_THROW_ON_ERROR);

        /*
         * The consistent parent's threshold is inherited verbatim - the
         * child carries th:c, not the th:8 of the local probability sampler.
         */
        self::assertSame(TraceFlags::SAMPLED, $aboveContext['flags'] & TraceFlags::SAMPLED);
        self::assertSame('th:c', $aboveContext['ot']);

        /*
         * The inconsistent parent's threshold is ignored and removed; the
         * child is still sampled because the parent was.
         */
        self::assertSame(TraceFlags::SAMPLED, $mismatchContext['flags'] & TraceFlags::SAMPLED);
        self::assertNull($mismatchContext['ot']);
        self::assertStringContainsString(
            'Mismatch between sampling threshold and sampled flag detected',
            $this->lastStderr,
        );

        /* The child of the dropped parent is dropped. */
        self::assertSame(0, $droppedContext['flags'] & TraceFlags::SAMPLED);

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['pt-above', 'pt-mismatch'],
            $this->spanNames($this->traces[0]),
        );
    }

    #[Group('sampler')]
    public function testProbabilitySamplerIgnoresInvalidExplicitRandomness(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              sampler:
                probability/development:
                  ratio: 0.5
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                /*
                 * A remote parent with an invalid randomness value (16 hex
                 * digits instead of exactly 14) whose trace ID's rightmost
                 * 56 bits are above the threshold: the invalid value must be
                 * ignored and the decision made from the trace ID.
                 */
                $remoteParent = SpanContext::createFromRemoteParent(
                    '4193e569320548f7b7d0a8f5e4d3c2b1',
                    '6e0c63258deeeff4',
                    TraceFlags::SAMPLED | TraceFlags::RANDOM,
                    (new TraceState())->with('ot', 'rv:0123456789abcdef'),
                );

                $child = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('invalid-rv')
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($remoteParent)))
                    ->startSpan();

                self::emitSpanContext($child->getContext());
                $child->end();
            },
        );

        /*
         * Had the invalid value (0x0123...) been used as randomness, the span
         * would have been dropped; it was sampled from the trace ID's own
         * bits instead, with the threshold alongside.
         */
        $context = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(TraceFlags::SAMPLED, $context['flags'] & TraceFlags::SAMPLED);

        $entries = array_flip(explode(';', (string) $context['ot']));

        self::assertArrayHasKey('th:8', $entries);

        /* The unrecognized value is passed through unmodified. */
        self::assertArrayHasKey('rv:0123456789abcdef', $entries);
        self::assertStringContainsString(
            'Invalid TraceState.ot rv value',
            $this->lastStderr,
        );

        self::assertCount(1, $this->traces);
        self::assertSame(
            ['invalid-rv'],
            $this->spanNames($this->traces[0]),
        );
    }

    public function testJaegerRemoteSamplerDialsPlaintextEndpoint(): void
    {
        $configFile = '';
        $this->assertPlaintextGrpcDial(
            static function (int $port) use (&$configFile): array {
                $configFile = sys_get_temp_dir() . '/otel-test-jaeger-' . uniqid() . '.yaml';
                file_put_contents($configFile, <<<YAML
                file_format: "1.2"

                tracer_provider:
                  sampler:
                    jaeger_remote/development:
                      endpoint: http://127.0.0.1:$port
                      interval: 50
                YAML);

                return ['OTEL_CONFIG_FILE' => $configFile];
            },
            static function (): void {
                /*
                 * Keep the event loop alive past the first 50 ms poll tick;
                 * no span is needed — the sampler polls on its own timer.
                 */
                \Amp\delay(0.5);
            },
        );

        @unlink($configFile);
    }

    /**
     * Emits the given span context as a JSON line on stdout, for assertions
     * in the parent process.
     */
    private static function emitSpanContext(object $spanContext): void {
        $traceState = $spanContext->getTraceState();

        fwrite(STDOUT, json_encode([
            'trace_id' => $spanContext->getTraceId(),
            'flags' => $spanContext->getTraceFlags(),
            'ot' => $traceState === null ? null : $traceState->get('ot'),
            'tracestate' => $traceState === null ? null : (string) $traceState,
        ]) . "\n");
    }
}

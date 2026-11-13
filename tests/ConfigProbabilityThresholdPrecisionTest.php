<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('config-file'), Group('traces')]
final class ConfigProbabilityThresholdPrecisionTest extends TestCase {
    use OTelEndpointTrait;

    /**
     * A remote parent whose trace ID carries the largest possible source of
     * randomness: the rightmost 56 bits, which W3C Trace Context Level 2
     * specifies as the randomness of a TraceID and which this document calls
     * "TraceID as a source of randomness", are all ones - 2**56 - 1,
     * 0xffffffffffffff.
     *
     * Every threshold in the matrix below is a 56-bit value not exceeding
     * that - the smallest-ratio case is exactly equal to it - so `R >= T`
     * holds for each configured ratio and the decision is positive in every
     * fixture run. A fixed randomness value is required:
     * the ratios reach 2**-56, and with randomly generated trace IDs a
     * fixture would essentially never produce a sampled span at those ratios.
     *
     * The parent carries the trace random flag, which is what confirms trace
     * randomness for a non-root span and keeps the "presumption of TraceID
     * randomness" compatibility warning out of these runs. Both SDKs take the
     * decision from the trace ID here, as the existing probability-sampler
     * tests already establish (ConfigProbabilitySamplerTest's threshold-matches-
     * decisions and invalid-randomness tests, both passing on both SDKs). The
     * parent's sampled flag is irrelevant to these samplers: a probability
     * sampler MUST ignore it, and the existing consistency-across-thresholds
     * test shows a child of a sampled remote parent being dropped by a local
     * probability sampler.
     */
    private const MAX_RANDOMNESS_REMOTE_PARENT = [
        'trace_id' => '4193e569320548f7b7ffffffffffffff',
        'span_id' => '6e0c63258deeeff4',
    ];

    /**
     * Probabilities whose rejection threshold is a single hexadecimal digit,
     * because the ratios are negative powers of two: one half, one quarter,
     * one eighth and one sixteenth map to the thresholds 2**55, 3*2**54,
     * 7*2**53 and 15*2**52.
     *
     * The expected values are the precision-4 column of the 1-in-N table in
     * tracestate-probability-sampling.md (`:440-454`), which shows `8`, `c`,
     * `e` and `f` for 1-in-2, 1-in-4, 1-in-8 and 1-in-16; for these ratios
     * the value is the same at precisions 3, 4 and 5. What these cases
     * measure is that the encoding omits trailing zeros, per the last line of
     * the reference algorithm (`:423`) and the `T`-value conversion at
     * `:457-468` ("emit it as a hexadecimal value (without a leading '0x'),
     * optionally with trailing zeros omitted"): the thresholds are
     * 0x80000000000000, 0xc0000000000000, 0xe0000000000000 and
     * 0xf0000000000000, but a sampled span must carry `th:8`, `th:c`, `th:e`
     * and `th:f` - not a padded `th:80000000000000`. A padded form decodes
     * as a different precision, and the table's values assume the trimmed one.
     *
     * Ratio one is the algorithm's special case (`:390-392`, "if probability
     * == 1: return \"0\""), rendered by the same `:457-468` rules as the
     * single digit `0` rather than an empty `th:` entry. Ratio zero is not
     * repeated here: existing tests cover it, and the specification notes
     * that "the zero value is not defined and that 'never' sampling is not a
     * form of probability sampling" (`:86`).
     *
     * Only the `probability/development` sampler is exercised here and below.
     * The SDK specification requires the threshold for that sampler
     * (trace-sdk.md:568, "When (R >= T), the OpenTelemetry TraceState SHOULD
     * be modified to include the key-value `th:T`") and for the
     * CompositeSampler it is configured through (trace-sdk.md:673-679). It
     * requires nothing of the kind for the data model's `trace_id_ratio_based`
     * sampler: that section (trace-sdk.md:464-535) states the algorithm "was
     * never specified" and lists no tracestate behaviour at all, so a `th`
     * value there would be an implementation detail rather than a requirement;
     * its decision distribution is covered by ConfigSamplingTest instead.
     */
    #[Group('sampler')]
    public function testProbabilitySamplerThresholdOmitsTrailingZeros(): void {
        $this->assertWrittenThresholds([
            /* 1-in-1: threshold 0. */
            ['ratio' => '1', 'th' => '0', 'span' => 'th-precision-one'],
            /* 1-in-2: 2**55 = 0x80000000000000. */
            ['ratio' => '0.5', 'th' => '8', 'span' => 'th-precision-half'],
            /* 1-in-4: 3*2**54 = 0xc0000000000000. */
            ['ratio' => '0.25', 'th' => 'c', 'span' => 'th-precision-quarter'],
            /* 1-in-8: 7*2**53 = 0xe0000000000000. */
            ['ratio' => '0.125', 'th' => 'e', 'span' => 'th-precision-eighth'],
            /* 1-in-16: 15*2**52 = 0xf0000000000000. */
            ['ratio' => '0.0625', 'th' => 'f', 'span' => 'th-precision-sixteenth'],
        ]);
    }

    /**
     * Probabilities that are not exactly representable, i.e. every non-dyadic
     * row of the 1-in-N table (`:440-454`). These are the cases decided by
     * the "leading F" precision compensation in
     * `probability_to_threshold_with_precision`.
     *
     * A threshold is derived from `1 - probability`, whose leading hexadecimal
     * digits are `f` for a small probability. Applying the configured
     * precision to plain digits would therefore spend it almost entirely on
     * those leading `f` digits. The reference algorithm instead scales the
     * precision by the base-2 exponent of the probability
     *
     * ```
     * _, exp = math.frexp(probability)                        # :395
     * precision = max(1, min(12, precision + exp // -4))      # :398
     * ```
     *
     * "This makes the configured precision apply to the significant digits of
     * the threshold for probabilities near zero" (`:426-427`).
     *
     * For a ratio of one third, `frexp` reports an exponent of -2, so the
     * precision stays 4 and the significant digits are the ones that produce
     * `aaab`. A sampler that rounds `2**56 * (1 - probability)` and merely
     * strips trailing zeros writes all fourteen digits, `aaaaaaaaaaaaac`; a
     * sampler using three digits writes `aab`. All three values appear in the
     * table, and `:435` states that "OpenTelemetry SDKs are recommended to use
     * 4 digits of precision by default" - which is what is asserted here.
     *
     * The ratio literals are quoted from the table's "Input probability"
     * column verbatim, so that the configuration parses to the same IEEE-754
     * double the table was computed from: `0.3333333333333333` is the shortest
     * decimal that round-trips to the double nearest one third, and fewer
     * digits would ask for a different probability. Note that the resulting
     * thresholds are deliberately not the exact values `(1 - p) * 2**56`:
     * `th:aaab` corresponds to a probability of 0.3333282470703125, the
     * "Actual probability (precision 4)" column of the same row.
     */
    #[Group('sampler')]
    public function testProbabilitySamplerThresholdPrecisionAppliesToSignificantDigits(): void {
        $this->assertWrittenThresholds([
            /* 1-in-3: `aaab`, not `aab` (precision 3) and not every digit. */
            ['ratio' => '0.3333333333333333', 'th' => 'aaab', 'span' => 'th-precision-third'],
            /* 1-in-5: `cccd`. */
            ['ratio' => '0.2', 'th' => 'cccd', 'span' => 'th-precision-fifth'],
            /* 1-in-10: `e666`. */
            ['ratio' => '0.1', 'th' => 'e666', 'span' => 'th-precision-tenth'],
            /* 1-in-100: `fd70a`. The Rejection Threshold definition works
             * through the same threshold at precision 5 as `ot=th:fd70a4`
             * (`:105-109`); at precision 4 the trailing `4` is dropped. */
            ['ratio' => '0.01', 'th' => 'fd70a', 'span' => 'th-precision-hundredth'],
            /* 1-in-1000: `ffbe77`. */
            ['ratio' => '0.001', 'th' => 'ffbe77', 'span' => 'th-precision-thousandth'],
            /* 1-in-10000: `fff9724`. */
            ['ratio' => '0.0001', 'th' => 'fff9724', 'span' => 'th-precision-ten-thousandth'],
            /* 1-in-100000: `ffff583a`. */
            ['ratio' => '0.00001', 'th' => 'ffff583a', 'span' => 'th-precision-hundred-thousandth'],
            /* 1-in-1000000: `ffffef39`. */
            ['ratio' => '0.000001', 'th' => 'ffffef39', 'span' => 'th-precision-millionth'],
        ]);
    }

    /**
     * The lower end of the valid probability range: "sampling probability is
     * valid in the range 2^-56 through 1" (`:84`), and "The minimum valid
     * sampling ratio is `2^-56`" (trace-sdk.md:558). The literal is the
     * shortest decimal that round-trips to that double; it is also accepted
     * by the data model, whose ratio property is a number with `minimum: 0`
     * and `maximum: 1`.
     *
     * The value asserted here is the one the normative definition of a
     * threshold requires at this probability. `Threshold = MaxAdjustedCount *
     * (1 - Probability)` with `MaxAdjustedCount = 2^56`
     * (trace-tracestate-handling.md:129-140, the same rule at
     * `tracestate-probability-sampling.md:98-100`), so at the minimum valid
     * probability the threshold is 2^56 - 1 = 0xffffffffffffff, written as
     * `th:ffffffffffffff`. That encoding is legal: "Valid values of the `th`
     * sub-fields include between 1 and 14 lowercase hexadecimal digits"
     * (trace-tracestate-handling.md:110), and a value is read back by
     * extending it with trailing zeros to 14 digits before parsing it as a
     * 56-bit unsigned number (`:116-117`). No normative text caps a threshold
     * at fewer than 14 digits, so this boundary is asserted against the
     * definition rather than against the reference algorithm.
     *
     * The reference algorithm produces a different value at this ratio, which
     * is recorded here and not asserted. The `min(12, ...)` precision clamp of
     * `:398` ("Precision is limited to 12 so that there is at least one digit
     * of precision in the final [:precision] statement below") leaves twelve
     * digits, and the arithmetic guard makes `rejection_prob` reach 2.0 here,
     * so the fallback `digits = "fffffffffffff"` of `:411-412` is what supplies
     * them; the result is `th:ffffffffffff`, the threshold 2^56 - 2^8. Read
     * back per trace-tracestate-handling.md:116-117 that is a probability of
     * 2^-48 - 256 times the configured ratio - and not only for this input: a
     * precision-12 truncation always zeroes the low 8 bits, so the finest
     * probability it can express is 2^-48. That is an artifact of that
     * pseudo-code, not a requirement.
     */
    #[Group('sampler')]
    public function testProbabilitySamplerThresholdAtMinimumValidProbability(): void {
        $this->assertWrittenThresholds(
            [
                ['ratio' => '1.3877787807814457e-17', 'th' => 'ffffffffffffff', 'span' => 'th-precision-minimum'],
            ],
            'The rejection threshold written by the probability sampler does not match '
                . 'MaxAdjustedCount * (1 - Probability) for the minimum valid probability 2^-56 '
                . '(trace-tracestate-handling.md:129-140, tracestate-probability-sampling.md:98-100).',
        );
    }

    /**
     * Runs a probability sampler for each ratio literal and compares the
     * `th:` value it writes for the resulting exported span against the
     * specification's own values.
     *
     * The comparison is made on the collected observations rather than case by
     * case: one deviation must not hide the others, because these values are
     * the measurement this test reports. An entry that is not a `th:` value
     * says what was observed instead - a dropped decision, a sampled span
     * without a threshold, or a span that was never exported.
     *
     * @param list<array{ratio: string, th: string, span: string}> $cases
     * @param string $basis the specification text these exact values come from
     */
    private function assertWrittenThresholds(
        array $cases,
        string $basis = 'The rejection threshold written by the probability sampler does not match the value that '
            . 'probability_to_threshold_with_precision computes for the same ratio at the recommended '
            . 'default precision of 4 digits (tracestate-probability-sampling.md:435).',
    ): void {
        $expected = [];
        $observed = [];

        foreach ($cases as $case) {
            $expected[$case['span']] = 'th:' . $case['th'];
            $observed[$case['span']] = $this->writeThresholdForRatio($case['ratio'], $case['span']);
        }

        self::assertSame($expected, $observed, $basis);
    }

    /**
     * Configures a `probability/development` sampler with the given ratio,
     * creates a span whose randomness value is at least every threshold, and
     * reports the OpenTelemetry TraceState entry of the span it exports.
     */
    private function writeThresholdForRatio(string $ratio, string $spanName): string {
        $exportsBefore = count($this->traces);

        $output = $this->runOTelConfig(
            sprintf(
                <<<'YAML'
                file_format: "1.2"

                tracer_provider:
                  sampler:
                    probability/development:
                      ratio: %s
                  processors:
                    - batch:
                        exporter:
                          otlp_http:
                            endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                YAML,
                $ratio,
            ),
            static function () use ($spanName): void {
                $remoteParent = SpanContext::createFromRemoteParent(
                    self::MAX_RANDOMNESS_REMOTE_PARENT['trace_id'],
                    self::MAX_RANDOMNESS_REMOTE_PARENT['span_id'],
                    TraceFlags::SAMPLED | TraceFlags::RANDOM,
                );

                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder($spanName)
                    ->setParent(Context::getCurrent()->withContextValue(Span::wrap($remoteParent)))
                    ->startSpan();

                self::emitSpanContext($span->getContext());
                $span->end();
            },
        );

        $context = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        $exported = [];
        foreach (array_slice($this->traces, $exportsBefore) as $payload) {
            $exported = [...$exported, ...$this->spanNames($payload)];
        }

        /*
         * The randomness value is at least every threshold in the matrix, so a
         * probability sampler configured with any of these ratios must reach a
         * positive decision; anything else is an observation to report rather
         * than a case to skip.
         */
        if (($context['flags'] & TraceFlags::SAMPLED) === 0) {
            return sprintf('no threshold: decision dropped (flags=0x%02x)', $context['flags']);
        }

        if ($exported !== [$spanName]) {
            return sprintf('no threshold: sampled span was not exported (%s)', json_encode($exported));
        }

        if (($context['ot'] ?? null) === null) {
            return 'no threshold: sampled span carries no OpenTelemetry tracestate entry';
        }

        return (string) $context['ot'];
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

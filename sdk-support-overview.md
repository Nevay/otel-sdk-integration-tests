# SDK support overview

Feature-by-feature pass/fail status of the suite's shared `spec` group — every
test that verifies specification-defined behavior — against the two SDKs under
`sdks/`. Generated from full suite runs (JUnit logs); regenerate with
`make test-all`, which writes `.junit-<sdk>.xml` for both SDKs.

- **tbachert run:** 437 tests executed, **437 passing / 0 failing** (`spec` +
  `tbachert` groups): the whole shared `spec` group passes, as do the 4
  vendor-specific tests.
- **official run:** 439 tests executed, **331 passing / 108 failing**
  (`spec` + `official` groups). All 108 failures are shared spec tests: 29 in
  environment-variable mode, 79 in configuration-file mode. As the JUnit log
  splits them: **12 errors, 96 assertion failures**. All 12 errors are the
  Prometheus exporter tests (11 configuration-file, 1 environment-variable),
  which fail before the SDK is built; every other failure is an assertion about
  exported data or emitted output.

The matrix covers the 433 shared `spec` tests (160 environment-variable mode,
273 configuration-file mode per SDK); vendor-specific tests
(`TbachertSpecificTest`, `OfficialSpecificTest`) are documented in the README
and are not part of this overview. A test that verifies specification-defined
behavior is kept and reported when an SDK fails it, rather than relaxed or
reclassified. Sections follow the specification's structure (context propagation,
then the signals in spec order: traces, metrics, logs); rows within a section are
organized by tested behavior.
Since the configuration file must support at least every option available via
environment variables, each env-based row has a config-file counterpart; rows
missing one side cover either data-model nodes without an environment
equivalent (config-only) or environment-value parsing semantics that have no
config-file equivalent (env-only).

Legend: ✅ all passing · 🟨 only `async`-group tests failing (passing once the
`async` tests are excluded) · ⚠️ partially passing · ❌ fully failing · – no tests
for that mode. Each spec test is counted in exactly one cell; the "Upstream
tracking" table at the bottom accounts for all 108 official failures by root
cause.

Accounting is machine-checkable: `matrix-map.json` assigns every spec test to
exactly one row, and `python3 check-matrix.py` verifies that assignment against
the JUnit logs (row membership, per-cell pass/fail counts, column totals).
The collapsed list under each table repeats the same tests individually with
per-SDK markers.
Footnotes are collected below the tables.

## Context propagation

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Propagators: tracecontext, baggage, b3 (injection & extraction) | ✅ 17/17 | ✅ 7/7 | ⚠️ 16/17¹ | ✅ 7/7 |
| Cross-signal correlation: logs & metrics carry the active span context | ✅ 4/4 | ✅ 1/1 | ✅ 4/4 | ❌ 0/1² |

<details>
<summary>Propagators: tracecontext, baggage, b3 (injection & extraction) — 24 tests (17 env · 7 config)</summary>

- `ConfigPropagatorTest::testConfigFileCanConfigureOnlyTraceContextPropagator` — tbachert ✅ · official ✅
- `ConfigPropagatorTest::testConfigFileCompositeListConfiguresPropagators` — tbachert ✅ · official ✅
- `ConfigPropagatorTest::testConfigFilePropagatorB3CompositeInjectsSingleHeader` — tbachert ✅ · official ✅
- `ConfigPropagatorTest::testConfigFilePropagatorB3MultiCompositeInjectsHeaders` — tbachert ✅ · official ✅
- `ConfigPropagatorTest::testConfigFilePropagatorsAreUsedForInjection` — tbachert ✅ · official ✅
- `ConfigPropagatorTest::testConfigFilePropagatorsConfigureTraceContextAndBaggage` — tbachert ✅ · official ✅
- `ConfigPropagatorTest::testConfigFileWithoutPropagatorInjectsNothing` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testB3MultiPropagatorExtractsParentContext` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testB3MultiPropagatorInjectsHeaders` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testB3NotSampledRemoteParentIsDropped` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testB3PropagatorExtractsParentContext` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testB3PropagatorInjectsSingleHeader` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testBaggagePropagator` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testBaggagePropagatorDecodesPercentEncodedValuesOnExtract` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testDefaultPropagatorInjectsTraceParent` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testDefaultPropagatorsInjectBaggageWithoutConfiguration` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testEmptyPropagatorsEnvironmentVariableFallsBackToDefaults` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testMultiplePropagators` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testNonePropagator` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testPropagatorsEnvironmentVariableAreUsedForInjection` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testPropagatorsEnvironmentVariableCanConfigureOnlyTraceContextPropagator` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testPropagatorsEnvironmentVariableConfiguresTraceContextAndBaggage` — tbachert ✅ · official ✅
- `EnvPropagatorTest::testPropagatorsEnvironmentVariableIsCaseInsensitive` — tbachert ✅ · official ❌
- `EnvPropagatorTest::testTraceContextPropagator` — tbachert ✅ · official ✅

</details>

<details>
<summary>Cross-signal correlation: logs & metrics carry the active span context — 5 tests (4 env · 1 config)</summary>

- `ConfigLogRecordTest::testLogRecordWithEventNameIsBridgedToSpanEvent` — tbachert ✅ · official ❌
- `EnvCorrelationTest::testAllSignalsCorrelateWithinOneTrace` — tbachert ✅ · official ✅
- `EnvCorrelationTest::testLogRecordEmittedInSpanCarriesTraceContext` — tbachert ✅ · official ✅
- `EnvCorrelationTest::testLogRecordInNestedSpanReferencesInnerSpan` — tbachert ✅ · official ✅
- `EnvCorrelationTest::testMetricsExemplarCarriesActiveSpanContext` — tbachert ✅ · official ✅

</details>

## Traces

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| End-to-end span export & cross-service context propagation (incl. mixed env/config-file services) | ✅ 1/1 | ✅ 2/2 | ✅ 1/1 | ✅ 2/2 |
| Sampling: always_on/off, trace-id ratio, parent-based (simple samplers) | ✅ 11/11 | ✅ 12/12 | ⚠️ 9/11¹ | ✅ 12/12 |
| Composite samplers in configuration mode: `rule_based` routing, `probability` and `parent_threshold` trace-state behaviour | – | ✅ 26/26 | – | ⚠️ 23/26³⁴²⁵²⁶ |
| `always_record` sampler (node that exists only in data model 1.2) | – | ✅ 1/1 | – | ❌ 0/1⁵ |
| `jaeger_remote` sampler (remote dial, strategies, initial sampler) | ✅ 5/5 | ✅ 3/3 | ❌ 0/5⁶ | ❌ 0/3⁶ |
| Span & attribute limits (count, value length, depth) | ✅ 11/11 | ✅ 6/6 | ⚠️ 10/11¹ | ⚠️ 3/6⁷ |
| Batch & simple span processors | ✅ 5/5 | ✅ 5/5 | ⚠️ 4/5⁸ | ✅ 5/5 |
| Span details: status, events, kinds, links, attribute value types | ✅ 5/5 | ✅ 1/1 | ✅ 5/5 | ✅ 1/1 |

<details>
<summary>End-to-end span export & cross-service context propagation (incl. mixed env/config-file services) — 3 tests (1 env · 2 config)</summary>

- `ConfigBasicTest::testConfigFileConfiguresTracerProvider` — tbachert ✅ · official ✅
- `EnvEndToEndTest::testTraceContextPropagatesAcrossServices` — tbachert ✅ · official ✅
- `EnvEndToEndTest::testTraceContextPropagatesBetweenEnvAndConfigFileModes` — tbachert ✅ · official ✅

</details>

<details>
<summary>Sampling: always_on/off, trace-id ratio, parent-based (simple samplers) — 23 tests (11 env · 12 config)</summary>

- `ConfigSamplingTest::testAlwaysOffSampler` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testAlwaysOnSampler` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testConfigModeDefaultSamplerIsParentBasedAlwaysOn` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testParentBasedSampler` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testParentBasedSamplerDefaultsToAlwaysOnForSampledParents` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testParentBasedSamplerHonorsPerParentOriginSamplers` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testParentBasedSamplerRootAlwaysOff` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testParentBasedSamplerRootRatioZeroStillSamplesRemoteChild` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testTraceIdRatioBasedSampler` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testTraceIdRatioBasedSamplerSamplesOnlyAFractionOfSpans` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testTraceIdRatioBasedSamplerWarnsWhenUsedAsChildSampler` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testTraceIdRatioBasedSamplerZero` — tbachert ✅ · official ✅
- `EnvSamplingTest::testAlwaysOffSampler` — tbachert ✅ · official ✅
- `EnvSamplingTest::testAlwaysOnSampler` — tbachert ✅ · official ✅
- `EnvSamplingTest::testEmptySamplerEnvironmentVariableFallsBackToDefault` — tbachert ✅ · official ✅
- `EnvSamplingTest::testInvalidSamplerArgumentFallsBackToDefaultSampling` — tbachert ✅ · official ❌
- `EnvSamplingTest::testParentBasedAlwaysOffSampler` — tbachert ✅ · official ✅
- `EnvSamplingTest::testParentBasedAlwaysOnSampler` — tbachert ✅ · official ✅
- `EnvSamplingTest::testParentBasedTraceIdRatioSampler` — tbachert ✅ · official ✅
- `EnvSamplingTest::testParentBasedTraceIdRatioZeroDropsRootButKeepsSampledRemoteChild` — tbachert ✅ · official ✅
- `EnvSamplingTest::testSamplerEnvironmentVariableIsCaseInsensitive` — tbachert ✅ · official ❌
- `EnvSamplingTest::testTraceIdRatioSamplerOne` — tbachert ✅ · official ✅
- `EnvSamplingTest::testTraceIdRatioSamplerZero` — tbachert ✅ · official ✅

</details>

<details>
<summary>Composite samplers in configuration mode: `rule_based` routing, `probability` and `parent_threshold` trace-state behaviour — 26 tests (0 env · 26 config)</summary>

- `ConfigProbabilitySamplerTest::testComposableProbabilitySampler` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerConsistentAcrossThresholds` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerDefaultsToRatioOne` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerUsesPropagatedExplicitRandomness` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerWritesThresholdTraceState` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testParentThresholdSamplerFollowsSampledRemoteParent` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testParentThresholdSamplerInheritsRemoteParentThreshold` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerDecidesFromExplicitRandomnessAndKeepsItOnDrop` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerIgnoresInvalidExplicitRandomness` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerPreservesPropagatedExplicitRandomness` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerThresholdMatchesSamplingDecisions` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerTraceStatePropagatesToChildSpans` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerWritesThresholdTraceState` — tbachert ✅ · official ✅
- `ConfigProbabilityThresholdPrecisionTest::testProbabilitySamplerThresholdAtMinimumValidProbability` — tbachert ✅ · official ✅
- `ConfigProbabilityThresholdPrecisionTest::testProbabilitySamplerThresholdOmitsTrailingZeros` — tbachert ✅ · official ❌
- `ConfigProbabilityThresholdPrecisionTest::testProbabilitySamplerThresholdPrecisionAppliesToSignificantDigits` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testCompositeAlwaysOffDropsAllSpans` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testCompositeAlwaysOnSamplesAllSpans` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testProbabilitySamplerWarnsWhenTraceRandomFlagIsNotSet` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testRuleBasedSamplerExcludedAttributePatterns` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerMatchesAttributePatterns` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerMatchesLocalParentOrigin` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerMatchesParentOrigin` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerRoutesByRemainingSpanKinds` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerRoutesBySpanKindAndAttributes` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerWithoutRulesDropsAllSpans` — tbachert ✅ · official ✅

</details>

<details>
<summary>`always_record` sampler (node that exists only in data model 1.2) — 1 tests (0 env · 1 config)</summary>

- `ConfigSamplingTest::testAlwaysRecordSamplerRecordsWithoutReporting` — tbachert ✅ · official ❌

</details>

<details>
<summary>`jaeger_remote` sampler (remote dial, strategies, initial sampler) — 8 tests (5 env · 3 config)</summary>

- `ConfigSamplingTest::testJaegerRemoteInitialSamplerAppliesWhileBackendUnreachable` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testJaegerRemoteSamplerDialsPlaintextEndpoint` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testJaegerRemoteSamplerInitializesWithoutExplicitInterval` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemoteInitialSamplerAppliesWhileBackendUnreachable` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemotePerOperationStrategyMatchesSpanNames` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemoteProbabilityStrategyIsApplied` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemoteRateLimitingStrategyLimitsSpans` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemoteSamplerDialsPlaintextEndpoint` — tbachert ✅ · official ❌

</details>

<details>
<summary>Span & attribute limits (count, value length, depth) — 17 tests (11 env · 6 config)</summary>

- `ConfigLimitsTest::testAttributeValueDepthLimitTruncatesNestedValues` — tbachert ✅ · official ❌
- `ConfigLimitsTest::testDefaultAttributeCountLimitIs128` — tbachert ✅ · official ✅
- `ConfigLimitsTest::testDefaultAttributeValueDepthLimitIs64` — tbachert ✅ · official ❌
- `ConfigLimitsTest::testGeneralAttributeLimits` — tbachert ✅ · official ✅
- `ConfigLimitsTest::testSpanLimits` — tbachert ✅ · official ✅
- `ConfigLimitsTest::testTracerProviderAttributeValueDepthLimitTruncatesNestedValues` — tbachert ✅ · official ❌
- `EnvSpanLimitsTest::testAttributeCountLimit` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testAttributeValueLengthLimit` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testEventAttributeCountLimit` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testLinkAttributeCountLimit` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testSpanAttributeCountLimit` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testSpanAttributeValueLengthLimitOverridesGlobalLimit` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testSpanEventCountLimit` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testSpanLinkCountLimit` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testUnparseableAttributeCountLimitFallsBackToDefault` — tbachert ✅ · official ❌
- `EnvSpanLimitsTest::testZeroEventCountLimitDropsAllEvents` — tbachert ✅ · official ✅
- `EnvSpanLimitsTest::testZeroLinkCountLimitDropsAllLinks` — tbachert ✅ · official ✅

</details>

<details>
<summary>Batch & simple span processors — 10 tests (5 env · 5 config)</summary>

- `ConfigBatchSpanProcessorTest::testBatchSpanProcessorExportTimeoutIsAccepted` — tbachert ✅ · official ✅
- `ConfigBatchSpanProcessorTest::testBatchSpanProcessorMaxExportBatchSize` — tbachert ✅ · official ✅
- `ConfigBatchSpanProcessorTest::testBatchSpanProcessorScheduleDelay` — tbachert ✅ · official ✅
- `ConfigBatchSpanProcessorTest::testMultipleProcessorsExportEachSpanToAllConfiguredExporters` — tbachert ✅ · official ✅
- `ConfigBatchSpanProcessorTest::testSimpleSpanProcessorExportsOnSpanEnd` — tbachert ✅ · official ✅
- `EnvBatchSpanProcessorTest::testBspDropsSpansWhenQueueIsFull` — tbachert ✅ · official ❌
- `EnvBatchSpanProcessorTest::testBspExportTimeout` — tbachert ✅ · official ✅
- `EnvBatchSpanProcessorTest::testBspMaxExportBatchSize` — tbachert ✅ · official ✅
- `EnvBatchSpanProcessorTest::testBspMaxQueueSize` — tbachert ✅ · official ✅
- `EnvBatchSpanProcessorTest::testBspScheduleDelayDoesNotLoseSpansAfterFlush` — tbachert ✅ · official ✅

</details>

<details>
<summary>Span details: status, events, kinds, links, attribute value types — 6 tests (5 env · 1 config)</summary>

- `ConfigBasicTest::testSpansExportedWithStatusEventsKindsAndLinks` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanAttributesPreserveValueTypes` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanEventsAreExportedWithAttributes` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanKindsAreExported` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanLinksAreExportedWithAttributes` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanStatusIsExported` — tbachert ✅ · official ✅

</details>

## Metrics

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Metric pipeline: instruments, series separation, gauge/counter semantics | – | ✅ 8/8 | – | 🟨 2/8⁹¹⁰ |
| Exemplar filter (always_on/off, trace_based, invalid fallback) | ✅ 4/4 | ✅ 4/4 | ⚠️ 3/4¹¹ | ⚠️ 1/4¹¹ |
| Temporality preference (cumulative/delta/lowmemory) | ✅ 2/2 | ✅ 5/5 | 🟨 1/2⁹¹⁰ | 🟨 1/5⁹¹⁰ |
| Default histogram aggregation (exponential buckets, spec boundaries) | ✅ 1/1 | ✅ 4/4 | ❌ 0/1¹² | ⚠️ 1/4¹² |
| Collection interval & periodic reader (batch size) | ✅ 1/1 | ✅ 3/3 | 🟨 0/1⁹¹⁰ | ⚠️ 1/3¹³ |
| Views: instrument & meter selection (incl. wildcards) | – | ✅ 14/14 | – | ✅ 14/14 |
| Views: attribute key filtering (include/exclude, precedence) | – | ✅ 4/4 | – | ✅ 4/4 |
| Views: aggregation overrides (histogram buckets, drop, sum/last-value) | – | ✅ 7/7 | – | ⚠️ 5/7¹⁴ |
| Views: metric renaming & description | – | ✅ 4/4 | – | ✅ 4/4 |
| Views: matching behavior (no-match, multiple streams, ordering) | – | ✅ 5/5 | – | ✅ 5/5 |
| Composable views (same-name merging, unnamed join, application order) | – | ✅ 6/6 | – | ❌ 0/6¹⁵ |
| Cardinality limits (overflow series) | – | ✅ 10/10 | – | ⚠️ 1/10¹⁶ |

<details>
<summary>Metric pipeline: instruments, series separation, gauge/counter semantics — 8 tests (0 env · 8 config)</summary>

- `ConfigMetricPipelineTest::testMetricsGaugeExportsLastValuePerCollection` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testMetricsPipelineExportsHistogramAggregationAcrossCollections` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testMetricsPipelineExportsMultipleInstrumentsAcrossMultipleCollectionCycles` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testMetricsPipelineKeepsMetricSeriesSeparateByAttributes` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testMultipleMetersProduceSeparateScopesInOneExport` — tbachert ✅ · official ✅
- `ConfigMetricPipelineTest::testObservableCounterExportsCumulativeObservedValue` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testObservableGaugeExportsObservedValue` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testUpDownCounterExportsNonMonotonicSum` — tbachert ✅ · official ✅

</details>

<details>
<summary>Exemplar filter (always_on/off, trace_based, invalid fallback) — 8 tests (4 env · 4 config)</summary>

- `ConfigMetricReaderTest::testMetricsExemplarFilterAlwaysOff` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testMetricsExemplarFilterAlwaysOn` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testMetricsExemplarFilterDefaultsToTraceBased` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testMetricsExemplarFilterTraceBasedCapturesOnlyUnderSampledSpans` — tbachert ✅ · official ❌
- `EnvMetricsTest::testInvalidExemplarFilterFallsBackToTraceBased` — tbachert ✅ · official ❌
- `EnvMetricsTest::testMetricsExemplarFilterAlwaysOff` — tbachert ✅ · official ✅
- `EnvMetricsTest::testMetricsExemplarFilterAlwaysOnCapturesWithoutSpan` — tbachert ✅ · official ✅
- `EnvMetricsTest::testMetricsExemplarFilterTraceBasedOnlyCapturesInSampledSpans` — tbachert ✅ · official ✅

</details>

<details>
<summary>Temporality preference (cumulative/delta/lowmemory) — 7 tests (2 env · 5 config)</summary>

- `ConfigMetricReaderTest::testMetricsCumulativeTemporalityPreservesStartTimestamp` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testMetricsExporterUsesCumulativeTemporality` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testMetricsExporterUsesDeltaTemporality` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testMetricsExporterUsesLowMemoryTemporality` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpFileMetricExporterDefaultsToCumulativeTemporality` — tbachert ✅ · official ❌
- `EnvMetricsTest::testMetricsTemporalityPreferenceEnvVarUsesDelta` — tbachert ✅ · official ❌
- `EnvMetricsTest::testMetricsTemporalityPreferenceEnvVarUsesLowMemory` — tbachert ✅ · official ✅

</details>

<details>
<summary>Default histogram aggregation (exponential buckets, spec boundaries) — 5 tests (1 env · 4 config)</summary>

- `ConfigMetricPipelineTest::testDefaultHistogramUsesSpecBoundaries` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testBase2ExponentialBucketHistogramDefaultsToSpecMaxScaleAndSize` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testExporterDefaultBase2ExponentialHistogramAggregation` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpFileMetricExporterDefaultsToExplicitBucketHistogramAggregation` — tbachert ✅ · official ✅
- `EnvMetricsTest::testDefaultHistogramAggregationEnvVarUsesExponentialBuckets` — tbachert ✅ · official ❌

</details>

<details>
<summary>Collection interval & periodic reader (batch size) — 4 tests (1 env · 3 config)</summary>

- `ConfigMetricReaderTest::testPeriodicMetricReader` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testPeriodicReaderMaxExportBatchSizeCountsDataPointsNotMetrics` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testPeriodicReaderMaxExportBatchSizeSplitsExports` — tbachert ✅ · official ❌
- `EnvMetricsTest::testMetricExportIntervalEnvVarControlsCollectionFrequency` — tbachert ✅ · official ❌

</details>

<details>
<summary>Views: instrument & meter selection (incl. wildcards) — 14 tests (0 env · 14 config)</summary>

- `ConfigViewsTest::testViewInstrumentNameSupportsWildcardPatterns` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectorMatchesAllSpecifiedInstrumentAndMeterCriteria` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectorRequiresAllSpecifiedCriteriaToMatch` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsByInstrumentTypeAndUnit` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsByMeterName` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsByMeterNameVersionAndSchemaUrl` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsByMeterSchemaUrl` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsByMeterVersion` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsGaugeByInstrumentType` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsObservableCounterByInstrumentType` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsObservableGaugeByInstrumentType` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsObservableUpDownCounterByInstrumentType` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewSelectsUpDownCounterByInstrumentType` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewWithEmptySelectorMatchesEveryInstrument` — tbachert ✅ · official ✅

</details>

<details>
<summary>Views: attribute key filtering (include/exclude, precedence) — 4 tests (0 env · 4 config)</summary>

- `ConfigViewsTest::testMetricsViewCanFilterAttributes` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewAttributeKeysSupportIncludeAndExcludePatterns` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewExcludedAttributesTakePrecedenceOverIncludedAttributes` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewFiltersAttributeKeys` — tbachert ✅ · official ✅

</details>

<details>
<summary>Views: aggregation overrides (histogram buckets, drop, sum/last-value) — 7 tests (0 env · 7 config)</summary>

- `ConfigViewsTest::testViewAggregationPreservesInstrumentAttributes` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewCanDropAnInstrument` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewExplicitDefaultAggregationUsesInstrumentKind` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewIgnoresAggregationIncompatibleWithInstrumentKind` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSupportsSumAndLastValueAggregations` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewUsesBase2ExponentialBucketHistogramAggregation` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewUsesExplicitBucketHistogramAggregation` — tbachert ✅ · official ✅

</details>

<details>
<summary>Views: metric renaming & description — 4 tests (0 env · 4 config)</summary>

- `ConfigViewsTest::testViewCanOverrideDescriptionWhilePreservingOriginalName` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewCanOverrideNameWhilePreservingOriginalDescription` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewPreservesOriginalNameAndDescriptionWhenOmitted` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewRenamesMetricAndChangesDescription` — tbachert ✅ · official ✅

</details>

<details>
<summary>Views: matching behavior (no-match, multiple streams, ordering) — 5 tests (0 env · 5 config)</summary>

- `ConfigViewsTest::testInstrumentWithoutMatchingViewRemainsUnchanged` — tbachert ✅ · official ✅
- `ConfigViewsTest::testMatchAllDropViewCanBeUsedAsDefaultWithSpecificView` — tbachert ✅ · official ✅
- `ConfigViewsTest::testMultipleMatchingViewsApplyTheirConfigurationsIndependently` — tbachert ✅ · official ✅
- `ConfigViewsTest::testMultipleMatchingViewsProduceMultipleStreams` — tbachert ✅ · official ✅
- `ConfigViewsTest::testViewIsAppliedBeforeMultipleMetricReadersExport` — tbachert ✅ · official ✅

</details>

<details>
<summary>Composable views (same-name merging, unnamed join, application order) — 6 tests (0 env · 6 config)</summary>

- `ConfigComposableViewsTest::testComposableUnnamedViewJoinsNamedStreamGroup` — tbachert ✅ · official ❌
- `ConfigComposableViewsTest::testComposableViewsApplyMatchingViewsInOrder` — tbachert ✅ · official ❌
- `ConfigComposableViewsTest::testComposableViewsMergeAttributeKeysUsingIntersection` — tbachert ✅ · official ❌
- `ConfigComposableViewsTest::testComposableViewsUseLastMatchingStreamConfiguration` — tbachert ✅ · official ❌
- `ConfigComposableViewsTest::testComposableViewsWithDifferentNamesProduceSeparateStreams` — tbachert ✅ · official ❌
- `ConfigComposableViewsTest::testComposableViewsWithSameNameProduceOneComposedStream` — tbachert ✅ · official ❌

</details>

<details>
<summary>Cardinality limits (overflow series) — 10 tests (0 env · 10 config)</summary>

- `ConfigMetricReaderTest::testReaderCardinalityLimitAppliesToGauge` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testReaderCardinalityLimitAppliesToHistogram` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testReaderCardinalityLimitAppliesToObservableCounter` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testReaderCardinalityLimitAppliesToObservableGauge` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testReaderCardinalityLimitAppliesToObservableUpDownCounter` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testReaderCardinalityLimitAppliesToUpDownCounter` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testReaderCardinalityLimitBucketsOverflowSeries` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testReaderCardinalityLimitsApplyPerInstrumentType` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewAggregationCardinalityLimitUsesOverflowSeries` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewAttributeFilteringOccursBeforeCardinalityLimit` — tbachert ✅ · official ✅

</details>

## Logs

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Log record content: severity, non-string & nested body types | ✅ 3/3 | ✅ 1/1 | ✅ 3/3 | ✅ 1/1 |
| Log record limits (attribute count & value length) | ✅ 3/3 | ✅ 2/2 | ✅ 3/3 | ❌ 0/2⁷ |
| Batch & simple log record processors | ✅ 5/5 | ✅ 3/3 | ⚠️ 4/5⁸ | ✅ 3/3 |
| Log severity threshold & trace-based filtering | – | ✅ 7/7 | – | ⚠️ 1/7¹⁷ |

<details>
<summary>Log record content: severity, non-string & nested body types — 4 tests (3 env · 1 config)</summary>

- `ConfigLogRecordTest::testLogRecordsExportedWithSeverityAndTypedBodies` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testLogBodySupportsNestedMaps` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testLogBodySupportsNonStringTypes` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testLogSeverityNumberAndTextAreExported` — tbachert ✅ · official ✅

</details>

<details>
<summary>Log record limits (attribute count & value length) — 5 tests (3 env · 2 config)</summary>

- `ConfigLogRecordTest::testLogRecordAttributeValueDepthLimit` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testLogRecordLimits` — tbachert ✅ · official ❌
- `EnvLogRecordTest::testLogRecordAttributeCountLimit` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testLogRecordAttributeValueLengthLimit` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testZeroAttributeCountLimitDropsAllAttributes` — tbachert ✅ · official ✅

</details>

<details>
<summary>Batch & simple log record processors — 8 tests (5 env · 3 config)</summary>

- `ConfigLogRecordTest::testBatchLogRecordProcessorMaxExportBatchSize` — tbachert ✅ · official ✅
- `ConfigLogRecordTest::testBatchLogRecordProcessorScheduleDelay` — tbachert ✅ · official ✅
- `ConfigLogRecordTest::testSimpleLogRecordProcessorExportsOnEmit` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testBlrpDropsLogRecordsWhenQueueIsFull` — tbachert ✅ · official ❌
- `EnvLogRecordTest::testBlrpExportTimeout` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testBlrpMaxExportBatchSize` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testBlrpMaxQueueSize` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testBlrpScheduleDelayDoesNotLoseLogsAfterFlush` — tbachert ✅ · official ✅

</details>

<details>
<summary>Log severity threshold & trace-based filtering — 7 tests (0 env · 7 config)</summary>

- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorDefaultConfigAppliesMinimumSeverityAndTraceBased` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorMinimumSeverity` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorMinimumSeverityFiltersBelowThreshold` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorMinimumSeverityInfoThreshold` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorTraceBasedDropsLogsFromUnsampledTraces` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorTraceBasedFalseDoesNotFilterUnsampledTraces` — tbachert ✅ · official ✅
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorTraceBasedFiltersUnsampledTraceRecords` — tbachert ✅ · official ❌

</details>

## Resource & entities

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Resource attributes & service.name | ✅ 8/8 | ✅ 6/6 | ✅ 8/8 | ✅ 6/6 |
| Entities (`OTEL_ENTITIES`, env detector) | ✅ 7/7 | ✅ 1/1 | ❌ 0/7¹⁸ | ❌ 0/1¹⁸ |
| Resource detectors & attribute include/exclude (config file) | – | ✅ 11/11 | – | ⚠️ 8/11¹⁹ |
| Unrecognized resource detector name (warn & skip) | – | ✅ 2/2 | – | ✅ 2/2 |

<details>
<summary>Resource attributes & service.name — 14 tests (8 env · 6 config)</summary>

- `ConfigResourceTest::testExplicitResourceAttributesOverrideAttributesList` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceAttributes` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceAttributesList` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceAttributesSupportArrayValues` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceAttributesSupportNonStringValues` — tbachert ✅ · official ✅
- `ConfigResourceTest::testServiceNameFallsBackToSpecDefaultWhenUnset` — tbachert ✅ · official ✅
- `EnvResourceTest::testDefaultResourceAttributesIdentifySdkAndComposerPackage` — tbachert ✅ · official ✅
- `EnvResourceTest::testEmptyServiceNameEnvironmentVariableFallsBackToDefault` — tbachert ✅ · official ✅
- `EnvResourceTest::testResourceAttributes` — tbachert ✅ · official ✅
- `EnvResourceTest::testResourceAttributesDecodeValuesButNotKeys` — tbachert ✅ · official ✅
- `EnvResourceTest::testResourceAttributesEnvironmentValuesAreStrings` — tbachert ✅ · official ✅
- `EnvResourceTest::testResourceAttributesEnvironmentVariableParsesWhitespace` — tbachert ✅ · official ✅
- `EnvResourceTest::testServiceName` — tbachert ✅ · official ✅
- `EnvResourceTest::testServiceNameTakesPrecedenceOverResourceAttribute` — tbachert ✅ · official ✅

</details>

<details>
<summary>Entities (`OTEL_ENTITIES`, env detector) — 8 tests (7 env · 1 config)</summary>

- `ConfigResourceTest::testEnvDetectorParsesEntitiesFromEnvironmentVariable` — tbachert ✅ · official ❌
- `EnvResourceTest::testEntitiesFromEnvironmentVariable` — tbachert ✅ · official ❌
- `EnvResourceTest::testEntityAttributeValuesArePercentDecoded` — tbachert ✅ · official ❌
- `EnvResourceTest::testEntityConflictingIdentityPreservesOnlyLast` — tbachert ✅ · official ❌
- `EnvResourceTest::testEntityDuplicateUsesLastOccurrence` — tbachert ✅ · official ❌
- `EnvResourceTest::testEntityInvalidSchemaUrlIsIgnored` — tbachert ✅ · official ❌
- `EnvResourceTest::testEntityMalformedDefinitionIsSkipped` — tbachert ✅ · official ❌
- `EnvResourceTest::testEntityWithoutSchemaUrlIsExported` — tbachert ✅ · official ❌

</details>

<details>
<summary>Resource detectors & attribute include/exclude (config file) — 11 tests (0 env · 11 config)</summary>

- `ConfigResourceTest::testContainerDetectorReportsContainerId` — tbachert ✅ · official ❌
- `ConfigResourceTest::testHostDetectorPopulatesHostAndOsAttributes` — tbachert ✅ · official ✅
- `ConfigResourceTest::testMultipleResourceDetectorsAreCombined` — tbachert ✅ · official ✅
- `ConfigResourceTest::testProcessDetectorReportsProcessAttributes` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceDetectionIsDisabledWithoutDetectionNode` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceDetectorExcludesSelectedAttributes` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceDetectorExclusionTakesPrecedenceOverInclusion` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceDetectorIncludesOnlySelectedAttributes` — tbachert ✅ · official ✅
- `ConfigResourceTest::testResourceSchemaUrlConflictingWithDetectedResourcesIsDropped` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceSchemaUrlMatchingDetectedResourcesIsExported` — tbachert ✅ · official ✅
- `ConfigResourceTest::testServiceDetectorReadsServiceNameFromEnvironmentVariable` — tbachert ✅ · official ❌

</details>

<details>
<summary>Unrecognized resource detector name (warn & skip) — 2 tests (0 env · 2 config)</summary>

- `ConfigResourceTest::testUnrecognizedResourceDetectorDoesNotPreventOtherDetectors` — tbachert ✅ · official ✅
- `ConfigResourceTest::testUnrecognizedResourceDetectorIsIgnoredWithWarning` — tbachert ✅ · official ✅

</details>

## Exporters & transport

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| OTLP HTTP exporter options: protocol, headers, compression, endpoints, timeouts, size limits, retries | ✅ 24/24 | ✅ 23/23 | ⚠️ 22/24¹ | ⚠️ 17/23²⁰ |
| OTLP gRPC exporter (all signals) | ✅ 12/12 | ✅ 4/4 | ✅ 12/12 | ✅ 4/4 |
| OTLP file exporter (newline-delimited JSON on disk) | – | ✅ 3/3 | – | ✅ 3/3 |
| TLS: CA trust & client certificates (all signals, both protocols) | ✅ 11/11 | ✅ 6/6 | ✅ 11/11 | ✅ 6/6 |
| Console exporter (stdout) | ✅ 3/3 | ✅ 3/3 | ✅ 3/3 | ✅ 3/3 |
| Prometheus exporter (pull, translation & escaping) | ✅ 1/1 | ✅ 11/11 | ❌ 0/1²¹ | ❌ 0/11²¹ |

<details>
<summary>OTLP HTTP exporter options: protocol, headers, compression, endpoints, timeouts, size limits, retries — 47 tests (24 env · 23 config)</summary>

- `ConfigBasicTest::testOtlpHttpDefaultsToProtobufEncodingWithoutCompression` — tbachert ✅ · official ✅
- `ConfigBasicTest::testOtlpHttpEncodingJsonIsApplied` — tbachert ✅ · official ✅
- `ConfigBasicTest::testOtlpHttpExporterHeadersAreSentToCollector` — tbachert ✅ · official ✅
- `ConfigBasicTest::testOtlpHttpGzipCompressionIsApplied` — tbachert ✅ · official ✅
- `ConfigBasicTest::testOtlpHttpMaxRequestSizeBlocksOversizedExports` — tbachert ✅ · official ❌
- `ConfigBasicTest::testOtlpHttpMaxResponseSizeRejectsLargeResponses` — tbachert ✅ · official ❌
- `ConfigBasicTest::testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ✅
- `ConfigLogRecordTest::testOtlpHttpEncodingJsonIsApplied` — tbachert ✅ · official ✅
- `ConfigLogRecordTest::testOtlpHttpExporterHeadersAreSentToCollector` — tbachert ✅ · official ✅
- `ConfigLogRecordTest::testOtlpHttpGzipCompressionIsApplied` — tbachert ✅ · official ✅
- `ConfigLogRecordTest::testOtlpHttpMaxRequestSizeBlocksOversizedExports` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testOtlpHttpMaxResponseSizeRejectsLargeResponses` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpHttpCompressionNoneSendsUncompressedExport` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpHttpEncodingJsonIsApplied` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpHttpExporterHeadersAreSentToCollector` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpHttpGzipCompressionIsApplied` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpHttpHeaderWithNullValueIsIgnored` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpHttpHeadersListIsSentToCollector` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpHttpHeadersTakePrecedenceOverHeadersList` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpHttpMaxRequestSizeBlocksOversizedExports` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpHttpMaxResponseSizeRejectsLargeResponses` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ✅
- `OtlpExporterTest::testCollectorRejectingExportDoesNotBreakShutdown` — tbachert ✅ · official ✅
- `OtlpExporterTest::testExporterHeadersDecodePercentEncodedValues` — tbachert ✅ · official ✅
- `OtlpExporterTest::testGenericOtlpCompressionAppliesToAllSignals` — tbachert ✅ · official ✅
- `OtlpExporterTest::testGenericOtlpEndpointIsUsedWithAppendedSignalPaths` — tbachert ✅ · official ✅
- `OtlpExporterTest::testGenericOtlpHeadersApplyToAllSignals` — tbachert ✅ · official ✅
- `OtlpExporterTest::testGenericOtlpProtocolAppliesToAllSignals` — tbachert ✅ · official ✅
- `OtlpExporterTest::testGenericOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ✅
- `OtlpExporterTest::testLogsOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ✅
- `OtlpExporterTest::testMetricExportTimeoutEnvVarDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ✅
- `OtlpExporterTest::testMetricsOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ✅
- `OtlpExporterTest::testOtlpHttpHonorsThrottlingResponse` — tbachert ✅ · official ✅
- `OtlpExporterTest::testOtlpHttpProtobufProtocolExportsAllSignals` — tbachert ✅ · official ✅
- `OtlpExporterTest::testOtlpHttpRetriesRetryableStatusCodes` — tbachert ✅ · official ✅
- `OtlpExporterTest::testOtlpProtocolEnvironmentVariableIsCaseInsensitive` — tbachert ✅ · official ❌
- `OtlpExporterTest::testOtlpUserAgentHeaderIdentifiesExporterLanguageAndVersion` — tbachert ✅ · official ✅
- `OtlpExporterTest::testPerSignalEndpointWithoutPathSendsToRoot` — tbachert ✅ · official ✅
- `OtlpExporterTest::testPerSignalOtlpCompressionAppliesToLogs` — tbachert ✅ · official ✅
- `OtlpExporterTest::testPerSignalOtlpCompressionAppliesToMetrics` — tbachert ✅ · official ✅
- `OtlpExporterTest::testPerSignalOtlpHeadersApplyToLogs` — tbachert ✅ · official ✅
- `OtlpExporterTest::testPerSignalOtlpHeadersApplyToMetrics` — tbachert ✅ · official ✅
- `OtlpExporterTest::testPerSignalOtlpHeadersReplaceGenericHeaders` — tbachert ✅ · official ✅
- `OtlpExporterTest::testSignalSpecificOtlpEndpointTakesPrecedenceOverGeneric` — tbachert ✅ · official ✅
- `OtlpExporterTest::testTracesOtlpTimeoutEnvVarDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ✅
- `OtlpExporterTest::testUnrecognizedProtocolFallsBackToDefault` — tbachert ✅ · official ❌

</details>

<details>
<summary>OTLP gRPC exporter (all signals) — 16 tests (12 env · 4 config)</summary>

- `GrpcTest::testConfigFileGrpcExporterExportsLogs` — tbachert ✅ · official ✅
- `GrpcTest::testConfigFileGrpcExporterExportsMetrics` — tbachert ✅ · official ✅
- `GrpcTest::testConfigFileGrpcExporterExportsSpans` — tbachert ✅ · official ✅
- `GrpcTest::testConfigFileInsecureGrpcExporter` — tbachert ✅ · official ✅
- `GrpcTest::testEnvGrpcGzipCompressionIsApplied` — tbachert ✅ · official ✅
- `GrpcTest::testEnvGrpcInsecureEnvVarDialsPlaintext` — tbachert ✅ · official ✅
- `GrpcTest::testEnvGrpcProtocolExportsSpans` — tbachert ✅ · official ✅
- `GrpcTest::testEnvGrpcProtocolSendsConfiguredHeadersAsMetadata` — tbachert ✅ · official ✅
- `GrpcTest::testEnvGrpcSchemelessEndpointIsSecureByDefault` — tbachert ✅ · official ✅
- `GrpcTest::testEnvGrpcWithoutTls` — tbachert ✅ · official ✅
- `GrpcTest::testGenericGrpcInsecureEnvVarDialsPlaintext` — tbachert ✅ · official ✅
- `GrpcTest::testGenericGrpcProtocolAppliesToAllSignals` — tbachert ✅ · official ✅
- `GrpcTest::testGrpcErrorStatusIsReportedWithoutRetry` — tbachert ✅ · official ✅
- `GrpcTest::testLogsInsecureEnvVarDialsPlaintext` — tbachert ✅ · official ✅
- `GrpcTest::testMetricsInsecureEnvVarDialsPlaintext` — tbachert ✅ · official ✅
- `GrpcTest::testPerSignalGrpcInsecureOverridesGeneric` — tbachert ✅ · official ✅

</details>

<details>
<summary>OTLP file exporter (newline-delimited JSON on disk) — 3 tests (0 env · 3 config)</summary>

- `ConfigBasicTest::testOtlpFileExporterWritesNewlineDelimitedJson` — tbachert ✅ · official ✅
- `ConfigLogRecordTest::testOtlpFileExporterWritesLogsToNewlineDelimitedJson` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testOtlpFileMetricExporterWritesNewlineDelimitedJson` — tbachert ✅ · official ✅

</details>

<details>
<summary>TLS: CA trust & client certificates (all signals, both protocols) — 17 tests (11 env · 6 config)</summary>

- `GrpcTest::testGrpcClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ✅
- `GrpcTest::testGrpcLogsClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ✅
- `GrpcTest::testGrpcMetricsClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ✅
- `TlsTest::testConfigFileCaFileTrustsSelfSignedCollector` — tbachert ✅ · official ✅
- `TlsTest::testConfigFileCaFileTrustsSelfSignedLogsCollector` — tbachert ✅ · official ✅
- `TlsTest::testConfigFileCaFileTrustsSelfSignedMetricsCollector` — tbachert ✅ · official ✅
- `TlsTest::testConfigFileClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ✅
- `TlsTest::testEnvCertificateTrustsSelfSignedCollector` — tbachert ✅ · official ✅
- `TlsTest::testEnvClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ✅
- `TlsTest::testGenericCertificateTrustsSelfSignedCollector` — tbachert ✅ · official ✅
- `TlsTest::testGenericClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ✅
- `TlsTest::testLogsCertificateEnvVarTrustsSelfSignedCollector` — tbachert ✅ · official ✅
- `TlsTest::testLogsClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ✅
- `TlsTest::testMetricsCertificateEnvVarTrustsSelfSignedCollector` — tbachert ✅ · official ✅
- `TlsTest::testMetricsClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ✅
- `TlsTest::testMissingClientCertificateIsRejected` — tbachert ✅ · official ✅
- `TlsTest::testUnknownCaIsRejectedByDefaultVerification` — tbachert ✅ · official ✅

</details>

<details>
<summary>Console exporter (stdout) — 6 tests (3 env · 3 config)</summary>

- `ConfigBasicTest::testConsoleExporterWritesSpansToStdout` — tbachert ✅ · official ✅
- `ConfigLogRecordTest::testConsoleExporterWritesLogsToStdout` — tbachert ✅ · official ✅
- `ConfigMetricReaderTest::testConsoleExporterWritesMetricsToStdout` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testLogsConsoleExporterWritesToStdout` — tbachert ✅ · official ✅
- `EnvMetricsTest::testMetricsConsoleExporterWritesToStdout` — tbachert ✅ · official ✅
- `EnvSdkTest::testConsoleExporterWritesSpansToStdout` — tbachert ✅ · official ✅

</details>

<details>
<summary>Prometheus exporter (pull, translation & escaping) — 12 tests (1 env · 11 config)</summary>

- `ConfigPrometheusExporterTest::testPrometheusEscapingAllowUtf8PreservesValidUtf8Names` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusEscapingDotsSchemeEscapesDotsAndUnderscores` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusEscapingUnderscoresReplacesNonLegacyCharacters` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusEscapingValuesSchemeEncodesCodePoints` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusReaderInfoMetricsCanBeDisabled` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusReaderServesMetricsOnConfiguredPort` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusResourceConstantLabelsAreExcluded` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusResourceConstantLabelsAreIncluded` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusTranslationStrategyNoUtf8EscapingWithSuffixes` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusTranslationStrategyUnderscoreEscapingWithoutSuffixes` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusTranslationStrategyWithoutSuffixes` — tbachert ✅ · official ❌
- `EnvMetricsTest::testPrometheusExporterServesMetricsOnConfiguredPort` — tbachert ✅ · official ❌

</details>

## SDK-level & cross-cutting

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| SDK enablement & per-signal exporter selection (`OTEL_SDK_DISABLED`, `*_EXPORTER`) | ✅ 10/10 | ✅ 1/1 | ⚠️ 9/10¹ | ✅ 1/1 |
| Provider configurators: scope filtering (wildcards, case sensitivity, isolation) | – | ✅ 18/18 | – | ✅ 18/18 |
| Env value parsing & leniency: empty values, invalid sampler/propagator, malformed attributes (env-only semantics) | ✅ 5/5 | – | ⚠️ 2/5¹ | – |
| `OTEL_LOG_LEVEL` (self-diagnostic output) | ✅ 1/1 | ✅ 2/2 | ✅ 1/1 | ⚠️ 1/2²² |
| Config file basics: no-op SDK, format versioning, missing file, independent signals | – | ✅ 4/4 | – | ✅ 4/4 |
| Component lists in configuration mode: provider `processors`/`readers` required and non-null; a null is not a valid value for an `array`-typed property | – | ✅ 11/11 | – | ⚠️ 10/11²³ |
| ID generation (random) | – | ✅ 1/1 | – | ❌ 0/1²⁴ |
| Variable substitution: `${}`, defaults, escaping, type coercion | – | ✅ 9/9 | – | ⚠️ 8/9²⁰ |
| Environment variable precedence & ignore rules in config-file mode | – | ✅ 5/5 | – | ✅ 5/5 |
| SDK self-observability disabled by default | – | ✅ 1/1 | – | ✅ 1/1 |

<details>
<summary>SDK enablement & per-signal exporter selection (`OTEL_SDK_DISABLED`, `*_EXPORTER`) — 11 tests (10 env · 1 config)</summary>

- `ConfigBasicTest::testDisabledConfigurationDisablesAllSignals` — tbachert ✅ · official ✅
- `EnvSdkTest::testDisabledSdkDoesNotDisablePropagators` — tbachert ✅ · official ✅
- `EnvSdkTest::testLogsExporterNoneDisablesOnlyLogExport` — tbachert ✅ · official ✅
- `EnvSdkTest::testMetricsExporterNoneDisablesOnlyMetricExport` — tbachert ✅ · official ✅
- `EnvSdkTest::testSdkDisabled` — tbachert ✅ · official ✅
- `EnvSdkTest::testSdkDisabledAcceptsCaseInsensitiveTrue` — tbachert ✅ · official ✅
- `EnvSdkTest::testSdkDisabledDoesNotAcceptOneAsTrue` — tbachert ✅ · official ✅
- `EnvSdkTest::testSdkEnabled` — tbachert ✅ · official ✅
- `EnvSdkTest::testTracesExporterNoneDisablesOnlyTraceExport` — tbachert ✅ · official ✅
- `OtlpExporterTest::testEmptyTracesExporterEnvironmentVariableFallsBackToOtlp` — tbachert ✅ · official ✅
- `OtlpExporterTest::testTracesExporterEnvironmentVariableIsCaseInsensitive` — tbachert ✅ · official ❌

</details>

<details>
<summary>Provider configurators: scope filtering (wildcards, case sensitivity, isolation) — 18 tests (0 env · 18 config)</summary>

- `ConfigConfiguratorsTest::testConfiguratorConfigurationIsIsolatedBetweenSignals` — tbachert ✅ · official ✅
- `ConfigConfiguratorsTest::testConfiguratorDefaultsAreEnabledWhenDefaultConfigIsOmitted` — tbachert ✅ · official ✅
- `ConfigConfiguratorsTest::testConfiguratorsSupportAsteriskWildcard` — tbachert ✅ · official ✅
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorCanDisableDefaultLoggersAndEnableMatchingLogger` — tbachert ✅ · official ✅
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorCanDisableMatchingLoggerWithDefaultEnabled` — tbachert ✅ · official ✅
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorMatchingIsCaseSensitive` — tbachert ✅ · official ✅
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorQuestionMarkWildcard` — tbachert ✅ · official ✅
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorSupportsWildcardMatching` — tbachert ✅ · official ✅
- `ConfigMeterConfiguratorTest::testMeterConfiguratorCanDisableDefaultMetersAndEnableMatchingMeter` — tbachert ✅ · official ✅
- `ConfigMeterConfiguratorTest::testMeterConfiguratorCanDisableMatchingMeterWithDefaultEnabled` — tbachert ✅ · official ✅
- `ConfigMeterConfiguratorTest::testMeterConfiguratorMatchingIsCaseSensitive` — tbachert ✅ · official ✅
- `ConfigMeterConfiguratorTest::testMeterConfiguratorQuestionMarkWildcard` — tbachert ✅ · official ✅
- `ConfigMeterConfiguratorTest::testMeterConfiguratorSupportsWildcardMatching` — tbachert ✅ · official ✅
- `ConfigTracerConfiguratorTest::testTracerConfiguratorCanDisableDefaultTracersAndEnableMatchingTracer` — tbachert ✅ · official ✅
- `ConfigTracerConfiguratorTest::testTracerConfiguratorCanDisableMatchingTracer` — tbachert ✅ · official ✅
- `ConfigTracerConfiguratorTest::testTracerConfiguratorMatchingIsCaseSensitive` — tbachert ✅ · official ✅
- `ConfigTracerConfiguratorTest::testTracerConfiguratorQuestionMarkWildcard` — tbachert ✅ · official ✅
- `ConfigTracerConfiguratorTest::testTracerConfiguratorSupportsWildcardMatching` — tbachert ✅ · official ✅

</details>

<details>
<summary>Env value parsing & leniency: empty values, invalid sampler/propagator, malformed attributes (env-only semantics) — 5 tests (5 env · 0 config)</summary>

- `EnvEdgeCasesTest::testEmptyEnvironmentVariableBehavesAsUnset` — tbachert ✅ · official ✅
- `EnvEdgeCasesTest::testInvalidSamplerArgumentDoesNotPreventSdkStartup` — tbachert ✅ · official ❌
- `EnvEdgeCasesTest::testInvalidSamplerDoesNotPreventSdkStartup` — tbachert ✅ · official ❌
- `EnvEdgeCasesTest::testMalformedResourceAttributesAreIgnored` — tbachert ✅ · official ❌
- `EnvEdgeCasesTest::testUnknownPropagatorDisablesPropagationWithoutBreakingStartup` — tbachert ✅ · official ✅

</details>

<details>
<summary>`OTEL_LOG_LEVEL` (self-diagnostic output) — 3 tests (1 env · 2 config)</summary>

- `ConfigBasicTest::testLogLevelDefaultsToInfo` — tbachert ✅ · official ❌
- `ConfigBasicTest::testLogLevelErrorSuppressesSdkWarnings` — tbachert ✅ · official ✅
- `EnvSdkTest::testLogLevelNoneSuppressesSelfDiagnostics` — tbachert ✅ · official ✅

</details>

<details>
<summary>Config file basics: no-op SDK, format versioning, missing file, independent signals — 4 tests (0 env · 4 config)</summary>

- `ConfigBasicTest::testConfigFileNewerMinorFormatIsAcceptedWithWarning` — tbachert ✅ · official ✅
- `ConfigBasicTest::testMissingConfigFileFailsInitialization` — tbachert ✅ · official ✅
- `ConfigBasicTest::testSignalsCanBeConfiguredIndependently` — tbachert ✅ · official ✅
- `ConfigBasicTest::testUnsupportedFileFormatResultsInNoOpSdk` — tbachert ✅ · official ✅

</details>

<details>
<summary>Component lists in configuration mode: provider `processors`/`readers` required and non-null; a null is not a valid value for an `array`-typed property — 11 tests (0 env · 11 config)</summary>

- `ConfigComponentListsTest::testLoggerProviderWithEmptyProcessorsFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testLoggerProviderWithNullProcessorsFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testLoggerProviderWithoutProcessorsKeyFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testMeterProviderWithEmptyReadersFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testMeterProviderWithEmptyViewsFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testMeterProviderWithNullReadersFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testMeterProviderWithNullViewsIsRejected` — tbachert ✅ · official ❌
- `ConfigComponentListsTest::testMeterProviderWithoutReadersKeyFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testTracerProviderWithEmptyProcessorsFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testTracerProviderWithNullProcessorsFailsInitialization` — tbachert ✅ · official ✅
- `ConfigComponentListsTest::testTracerProviderWithoutProcessorsKeyFailsInitialization` — tbachert ✅ · official ✅

</details>

<details>
<summary>ID generation (random) — 1 tests (0 env · 1 config)</summary>

- `ConfigBasicTest::testIdGeneratorRandomProducesSpecConformIds` — tbachert ✅ · official ❌

</details>

<details>
<summary>Variable substitution: `${}`, defaults, escaping, type coercion — 9 tests (0 env · 9 config)</summary>

- `ConfigSubstitutionTest::testDefaultValueIsUsedOnlyWhenVariableIsUnset` — tbachert ✅ · official ✅
- `ConfigSubstitutionTest::testDoubleDollarEscapesSubstitution` — tbachert ✅ · official ✅
- `ConfigSubstitutionTest::testEnvironmentValueCannotInjectYamlStructure` — tbachert ✅ · official ✅
- `ConfigSubstitutionTest::testInvalidSubstitutionFailsInitialization` — tbachert ✅ · official ✅
- `ConfigSubstitutionTest::testMultipleReferencesInOneString` — tbachert ✅ · official ✅
- `ConfigSubstitutionTest::testPlainAndEnvPrefixedReferencesResolveToTheSameValue` — tbachert ✅ · official ✅
- `ConfigSubstitutionTest::testSubstitutedBooleanIsCoercedForTypedNode` — tbachert ✅ · official ✅
- `ConfigSubstitutionTest::testSubstitutedHexIntegerIsCoercedForTypedNode` — tbachert ✅ · official ❌
- `ConfigSubstitutionTest::testSubstitutionIsNotRecursive` — tbachert ✅ · official ✅

</details>

<details>
<summary>Environment variable precedence & ignore rules in config-file mode — 5 tests (0 env · 5 config)</summary>

- `ConfigBasicTest::testConfigFileModeIgnoresOtherEnvironmentVariables` — tbachert ✅ · official ✅
- `ConfigPrecedenceTest::testConfigFileIgnoresSdkDisabledEnvVar` — tbachert ✅ · official ✅
- `ConfigPrecedenceTest::testConfigFileIgnoresTracesSamplerEnvVar` — tbachert ✅ · official ✅
- `ConfigPrecedenceTest::testConfigFileResourceAttributesTakePrecedenceOverEnvironment` — tbachert ✅ · official ✅
- `ConfigPrecedenceTest::testConfigModeDefaultPropagatorIsNone` — tbachert ✅ · official ✅

</details>

<details>
<summary>SDK self-observability disabled by default — 1 tests (0 env · 1 config)</summary>

- `ConfigSelfObservabilityTest::testSelfObservabilityIsDisabledByDefault` — tbachert ✅ · official ✅

</details>

## Footnotes

¹ **Invalid environment values are not tolerated (10 tests).** Eight of them
abort SDK initialization instead of logging a warning and falling back to the
default: unrecognized or differently-cased identifiers (`unknown sampler:
parentbased_always_on`, `unknown sampler: not-a-sampler`, `unknown protocol:
http/protobuf`, `unknown protocol: carrier-pigeon`, `span exporter factory not
defined for: otlp`), an unparseable numeric limit (`value has invalid type
"string"`), and invalid `OTEL_TRACES_SAMPLER_ARG` values (`value "bogus"
contains non-numeric value`, `value "not-a-number" contains non-numeric
value`). The remaining two drop a value without aborting initialization:
a malformed `OTEL_RESOURCE_ATTRIBUTES` list loses the valid entry as well
(`Resource attribute "good.key" was not found.`), and a differently-cased
`OTEL_PROPAGATORS` list yields no `baggage` propagator (`Failed asserting that
an array has the key 'baggage'.`). The specification requires the fallback.
Reported upstream: the unrecognized enum values as
[#2137](https://github.com/open-telemetry/opentelemetry-php/issues/2137), the
unparseable numeric values as
[#2136](https://github.com/open-telemetry/opentelemetry-php/issues/2136), and the
case-sensitive matching of enum values as
[#2138](https://github.com/open-telemetry/opentelemetry-php/issues/2138); the
`OTEL_RESOURCE_ATTRIBUTES` list handling is untracked.  
² **No log-record-to-span-event bridge in configuration mode (1 test).** The
`event_to_span_event_bridge/development` processor node is absent from the
v1.0.0 `logger_provider` schema and appears there only from data model 1.1, so
the configuration is rejected before the SDK is built: `component
"opentelemetry\sdk\logs\logrecordprocessorinterface" uses unknown provider
"event_to_span_event_bridge/development", available providers are "batch",
"simple"`. `open-telemetry/sdk` implements `file_format: "1.0"`, as its own
runtime warning states, so rejecting a configuration that installs the bridge is
what the 1.0 model implies — it is not evidence of a missing 1.0
implementation. Counted with the newer-data-model row of the tracking table.  
³ **Composite samplers in configuration mode: the `rv` and parent-threshold
coverage in this cell is SHOULD-level or supplemental-guidance coverage, and the
cross-SDK difference it measured is an implementation-defined warning (no failing
test).** `ConfigProbabilitySamplerTest` passes in full on both SDKs in this run.
Its valid-`rv` propagation assertions are SHOULD-level coverage that both SDKs
satisfy (`trace-tracestate-handling.md:169-170`, "Explicit randomness values …
SHOULD NOT be erased"). Its parent-threshold assertions follow the supplemental
guidance that section declares at `tracestate-probability-sampling.md:237-241`,
not a normative requirement. What this run does show in that area is a
difference in the self-diagnostic, not in the decision: for a parent whose
threshold is apparently inconsistent with its sampled flag, both SDKs erase the
threshold and keep the parent's sampled decision — official by comparing
`isSampled()` against the threshold and falling through
(`Trace/Sampler/Consistent/ComposableParentThreshold.php`, "An apparently
inconsistent `th` is erased, that is treated as absent"), tbachert by inheriting
the parent threshold unconditionally with the check in
`CompositeSampler.php:70-72`. tbachert additionally emits a warning no
specification requires (`otel.WARNING: Mismatch between sampling threshold and
sampled flag detected. Ignoring sampling threshold.`), while official logs
nothing: its whole `Trace/Sampler/Consistent/` package contains no logger call.
The two therefore do not behave identically there, and neither side's behavior
in that spot is a conformance gap. The failures in this cell are footnotes ⁴, ²⁵
and ²⁶ for `open-telemetry/sdk`; none of them is caused by what this footnote
describes.  
⁴ **Sampler compatibility warnings: both are asserted presence-only, and
official omits one of them (1 test).** `ConfigSamplingTest` covers the two
warnings the specification requires: `trace/sdk.md:516-517`, a
`TraceIdRatioBased` sampler queried with a non-empty parent span context
(`testTraceIdRatioBasedSamplerWarnsWhenUsedAsChildSampler`, passing on both
SDKs), and `trace/sdk.md:582-584`, a probability sampler presuming TraceID
randomness while the Trace random flag is unset
(`testProbabilitySamplerWarnsWhenTraceRandomFlagIsNotSet`, the failing test).
Both spec statements are explicitly illustrative — "SHOULD emit a warning **such
as**" and "As an **example** of this compatibility warning" — so each test
asserts only that a diagnostic appears, never its wording and never a number of
occurrences. tbachert emits both: `otel.WARNING: The TraceIdRatioBased sampler is
operating as a child sampler; the behavior is subject to change. Please upgrade
this SDK configuration to use ProbabilitySampler.` and `otel.WARNING: The sampler
is presuming TraceIDs are random and expects the Trace random flag to be set in
confirmation. Please upgrade your caller(s) to use W3C Trace Context Level 2.`
official emits the first one with the spec body verbatim — `OpenTelemetry:
[warning] … in sdks/official/vendor/open-telemetry/sdk/Trace/Sampler/
TraceIdRatioBasedSampler.php(74)`,
once per sampler instance — and has no ProbabilitySampler/TraceID-randomness
warning anywhere: no such string occurs in its vendor tree, and the
`Consistent/CompositeSampler` that `probability/development` builds has no logger
at all. That single absence is the only gap these two tests add. tbachert's
second warning differs from the spec's example wording (it says "The sampler is
presuming" for a node configured as `probability/development`, where the example
names the `ProbabilitySampler`); the wording is illustrative, so that is not a
conformance gap, and the message is not especially actionable either — it states
an internal presumption instead of naming the configuration a user would change.

Test design, not a defect: official logs `file_format "1.2" is newer than the
1.0 implemented by this SDK; configuration it introduces may be ignored` on every
configuration-file run. A literal "no warning in the control run" assertion would
therefore fail on official, and a bare "at least one warning in the trigger run"
assertion would pass there vacuously, so each test runs a control first under one
identical configuration and asserts that the trigger run emits more warnings than
that baseline. The notice is only a log statement — the `FILE_FORMAT_MAJOR` /
`FILE_FORMAT_MINOR` gate in
`Configuration/Internal/ComponentProvider/OpenTelemetrySdk.php`, commented "Warned
here rather than during validation", filters no configuration node — and it is not
why the node under test had an effect: probes on official show
`probability/development` genuinely governed the child span, whose context carried
the `ot=th:…` tracestate entry that `always_on` in the same position did not
write, and the same node with `ratio: 0.0` dropped a child of a sampled remote
parent that a default parent-based sampler would have kept.  
⁵ **`always_record` (1 test).** The sampler node exists only in data model 1.2
(`AlwaysRecordSampler` appears in the v1.2.0 and main configuration schemas, not
in v1.0.0 or v1.1.0), so the configuration is rejected before the SDK is built:
`component "opentelemetry\sdk\trace\samplerinterface" uses unknown provider
"always_record", available providers are "always_off", "always_on",
"composite/development", "parent_based", "probability/development",
"trace_id_ratio_based"`. Same missing newer data model as the data-model row of
the tracking table.  
⁶ **`jaeger_remote` is not implemented (8 tests: 5 env, 3 configuration).**
`OTEL_TRACES_SAMPLER=jaeger_remote` aborts initialization with
`unknown sampler: jaeger_remote` (4 env tests), and the
`jaeger_remote/development` sampler node — which *is* part of data model 1.0 —
has no provider, so configuration-file use fails with `component
"opentelemetry\sdk\trace\samplerinterface" uses unknown provider
"jaeger_remote/development", available providers are …` (3 tests). The two
remote-dial tests (1 env, 1 configuration) show the same gap one step earlier:
because no jaeger sampler is created, nothing dials the suite's sampling server,
and they fail as `The gRPC client did not dial the endpoint within 15 seconds
(Amp\TimeoutException: Operation timed out)`. All eight tests carry
`#[Group('async'), Group('jaeger')]`, so these cells are **not** 🟨:
`--exclude-group async` removes every test in them, which empties the cell
instead of turning it green. Untracked upstream.  
⁷ **Attribute and log-record limits in configuration mode (5 tests).** Three of
them are rejections of `attribute_value_depth_limit`, which exists only in data
model 1.2 (see the data-model row of the tracking table):
`unrecognized option "attribute_value_depth_limit" under
"open_telemetry.attribute_limits"`, the same under
`"open_telemetry.tracer_provider.limits"` and under
`"open_telemetry.logger_provider.limits"`. The other two are limits that no
configuration is needed to observe and that are not enforced: nested attribute
values are dropped entirely instead of being truncated at the default value-depth
limit (`Failed asserting that actual size 0 matches expected size 1.`), and log
record attribute count/length limits are ignored (`Failed asserting that actual
size 3 matches expected size 2.`). The value-depth default the test asserts (64)
is itself specified only in data model 1.2, so that test measures the missing
newer model as much as an unenforced limit; it is counted with the unenforced
limits here, and moving it to the data-model row is a classification decision
that has not been made. Untracked upstream.  
⁸ **Queue-full behavior cannot be observed (2 tests).** The batch span processor
and the batch log record processor flush synchronously when the queue is full,
so the queue never overflows and nothing is dropped. Untracked upstream.  
⁹ **The periodic exporting `MetricReader` does not repeat collections (12
tests).** The export interval is not used by the reader, so only a single
collection happens at shutdown: multi-collection pipeline behavior (series
separation, gauge/observable instruments across collections), delta temporality,
cumulative start timestamps and interval-controlled collection all become
unobservable. Issue
[#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884)
("Periodic exporting MetricReader not working", open).  
¹⁰ **Cells marked 🟨:** every failing test in such a cell belongs to the `async`
group, so the cell turns green when those tests are excluded
(`--exclude-group async`). They occur in the official columns only — the tbachert
run passes the same tests — and they are counted in the run totals like any
other failure. Their cause is footnote ⁹.  
¹¹ **Exemplars are not captured (4 tests).** The filter *values* are accepted
since [PR #2057](https://github.com/open-telemetry/opentelemetry-php/pull/2057)
(closed issue
[#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054)), but
no exemplars are exported for `always_on` or
`trace_based`, and an invalid filter value falls back to no exemplars instead of
the default `trace_based`. The invalid-value fallback is reported upstream as
[#2137](https://github.com/open-telemetry/opentelemetry-php/issues/2137) — an
unrecognized enum value must be ignored in favour of the default — and the
case-insensitive reading of that variable as
[#2138](https://github.com/open-telemetry/opentelemetry-php/issues/2138); the
missing exemplar capture is untracked.  
¹² **Default histogram aggregation is not applied (4 tests).**
`OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION` is ignored, and the
`default_histogram_aggregation` node "parses but is not applied yet" per the
configuration package's changelog; `base2_exponential_bucket_histogram` parses, then
falls back to the reader default, so the spec's default boundaries and scales
are never produced. Untracked upstream.  
¹³ **`max_export_batch_size` on a periodic reader (2 tests).** Only the metric
placement of this node is newer than the implemented model: the node is part of
data model 1.0's batch log record and batch span processors — those tests pass
on both SDKs — while 1.0's `meter_provider` has no `max_export_batch_size`
under `readers` and gains it only from data model 1.1. Both metric
configurations are therefore rejected before the reader is built (`unrecognized
option "max_export_batch_size/development" under "periodic"`). Counted with the
newer-data-model row of the tracking table.  
¹⁴ **View aggregation overrides are not honored (2 tests).** A view selecting a
base2 exponential histogram does not produce the expected metric stream, and an
aggregation incompatible with the instrument kind is not ignored. Untracked
upstream.  
¹⁵ **Composable views (6 tests).** `view_matching_mode/development` exists only
in data model 1.2, so every configuration using composable views is rejected at
initialization.  
¹⁶ **Cardinality limits are not applied (9 tests).** Per the configuration
package's changelog,
"Cardinality limits are not applied yet": reader and view cardinality limits
produce no overflow series and are not enforced per instrument type. Untracked
upstream.  
¹⁷ **`logger_configurator` severity and trace filters are inert (6 tests).**
`minimum_severity` and `trace_based` are parsed but, per the configuration
package's changelog,
"not applied yet": logs below the threshold and records from unsampled traces
are exported. Untracked upstream.  
¹⁸ **`OTEL_ENTITIES` is not implemented (8 tests).** The environment entity
detector does not read `OTEL_ENTITIES` — not for the plain form, duplicates,
conflicting identities, malformed definitions, schema-url handling or percent
decoding — and the configuration-file env detector reports no entities either.
Untracked upstream.  
¹⁹ **Resource detectors in configuration mode (3 tests).** The container detector
reports no container id (`Resource attribute "container.id" was not found.`);
the service detector does read `service.name` from `OTEL_SERVICE_NAME` — that
assertion passed — and what is missing is the instance id it also specifies
(`Resource attribute "service.instance.id" was not found.`); and a resource
`schema_url` conflicting with detected resources is not dropped
(`Failed asserting that two arrays are identical.`, with the conflicting
`https://example.com/schemas/custom-1.0` still present). Untracked upstream.  
²⁰ **OTLP request/response size limits (7 tests).** `max_request_size` and
`max_response_size` on exporter configuration nodes exist only in data model
1.2, so the six configurations (traces, logs, metrics) are rejected
(`unrecognized option "max_request_size" under "otlp_http". available options
are "compression", "encoding", "endpoint", "headers", "headers_list",
"timeout", "tls"`). The seventh test is the one that substitutes a hexadecimal
value into such a node; it fails on that same unknown node
(`invalid configuration for path "batch.exporter": unrecognized option
"max_request_size" under "otlp_http"`), not on coercing the substituted value.  
²¹ **Prometheus exporter is not available (12 tests).** In configuration mode
the pull-type meter reader itself has no provider: `Component
"OpenTelemetry\SDK\Metrics\MetricReaderInterface" uses unknown provider "pull",
available providers are "periodic"` (11 tests). In environment mode the exporter
selection fails with "Metric exporter factory not registered for protocol:
prometheus" (1 test). A pull exporter additionally needs an async runtime to
serve the reader. Not reported upstream (by decision).  
²² **`OTEL_LOG_LEVEL` diagnostics in configuration mode (1 test).** Only one of
the two tests in this row fails, and neither failure is caused by the
`file_format "1.2" is newer than 1.0` notice. What the run shows is that a
configured `log_level` re-levels only the diagnostics the configuration path
emits while it creates components: `Configuration/Internal/ComponentProvider/OpenTelemetrySdk.php`
resolves `log_level`, calls `SdkBuilder::setLogLevel()` and installs
`withLogger(new ConfiguredLogger($logLevel))` on the component-creation context,
whereas the SDK-wide `Logging::setLogLevel()` is only applied by
`SdkBuilder::buildAndRegisterGlobal()` — and the file-based autoloader
(`SDK/SdkAutoloader::fileBasedInitializer()`) builds the SDK with
`SdkBuilder::build()` instead. Diagnostics that SDK internals emit through the
API's own logger therefore keep the environment default (`info`): at
`log_level: error` the `file_format` notice *is* suppressed, while the span-limit
warning from `SDK/Trace/Span.php(405)` survives
(`Expected no span-limit diagnostic at the error log level. Failed asserting
that 'OpenTelemetry: [warning] Dropped span attributes, links or events in
/php/sdks/official/vendor/open-telemetry/sdk/Trace/Span.php(405)' does not
match …`). The companion test, which asserts that nothing below error severity
is reported at `log_level: error`, passes. Untracked upstream.  
²³ **An explicit `meter_provider.views: null` is accepted by official; the
suite asserts rejection (1 test).** Two readings of the configuration model
exist for a present-but-null `views` and the suite pins the strict one, so this
cell measures a data-model interpretation rather than a confirmed implementation
defect.

The strict reading: in the configuration model (v1.0.0, v1.1.0, v1.2.0 and main
alike) `TracerProvider.processors`, `MeterProvider.readers` and
`LoggerProvider.processors` are `type: array` with `minItems: 1` and *are*
listed in their type's `required` array, each described as "Property is required
and must be non-null", with no default or null behaviour defined. `views` is
`type: array` with `minItems: 1` too, but it is *not* in `MeterProvider`'s
`required` array. Nullability in this model is spelled out in the type union:
`ExemplarFilter` in the same schema file is `type: ["string", "null"]` and
every genuinely nullable property does the same, while `"null"` is not part of
`views`' union. `opentelemetry-configuration`'s CONTRIBUTING.md states the
negative case for sibling nodes directly — "Setting `exporter` to `null` or any
non-object value is invalid", "Setting `limits` to `null` is invalid"
(<https://github.com/open-telemetry/opentelemetry-configuration/blob/main/CONTRIBUTING.md>).
A present `views` must therefore hold a value of its declared type, and `null`
is rejected like the too-short list `views: []`, which both SDKs reject; that
both reject `views: []` shows the shared rule is the declared type and its
`minItems: 1`. Nulls are not hypothetical input: substituting an undefined
environment variable produces one (`configuration/data-model.md`), which is why
the env-substitutable properties declare the union.

The permissive reading: "Parse MUST differentiate between properties that are
missing and properties that are present but null"
(<https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/configuration/sdk.md#L212-L213>),
"If a property is present and the value is null, Create MUST use the
`nullBehavior`, or `defaultBehavior` if `nullBehavior` is not set" (same file,
[#L270-L271](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/configuration/sdk.md#L270-L271)).
`views` declares `defaultBehavior: no views are registered`
(<https://github.com/open-telemetry/opentelemetry-configuration/blob/main/schema/meter_provider.yaml>),
which the compiled JSON encodes in the node description as "If omitted, no views
are registered", and it defines no `nullBehavior` — under this reading `views: ~`
is an omitted key. The third MUST, "If a property is required, and not present,
Create MUST return an error."
([#L272](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/configuration/sdk.md#L272)),
covers the required provider lists only, which both SDKs enforce.

What the fresh run shows: official implements the permissive reading. With a
valid `readers` list it emits no diagnostic at all for `views: ~`, initialization
succeeds and the counter is exported under its original name and value; its
output is identical to the same fixture with the key omitted. tbachert treats a
present null as a list that violates the node's `minItems: 1` and fails
initialization:
`[timestamp] otel.ERROR: Error during OpenTelemetry initialization: …
InvalidConfigurationException: The path "open_telemetry\0.meter_provider\0.views"
should have at least 1 element(s) defined.` (the path separators are NUL bytes)
— byte-identical to its message for `views: []`, while the fixture with the key
omitted builds the provider with no diagnostic, so the error is attributable to
the present null. That message states a minimum length for a value that is not a
list at all, which is not a helpful way to report a present-but-null property;
it is wording, and it is not treated here as a conformance gap.

`ConfigComponentListsTest::testMeterProviderWithNullViewsIsRejected` asserts
rejection (initialization fails, nothing exported). Whether a present null on a
non-required `array`-typed node must be rejected, or may fall back to its
`defaultBehavior`, is an interpretation of the data model's nullability encoding;
it is worth raising upstream as a question rather than as a bug report against
either SDK, and it is listed in the tracking table as such. The ten other tests
of the row pass on both SDKs.  
²⁴ **`id_generator` (1 test).** The node is not 1.2-only: it is absent from data
model 1.0's `tracer_provider` and appears there from 1.1. The configuration is
therefore rejected before the SDK is built (`unrecognized option
"id_generator" under "open_telemetry.tracer_provider"`). Counted with the
newer-data-model row of the tracking table.  
²⁵ **`open-telemetry/sdk` writes a rejection threshold one unit below the value
the definition requires (1 test).** `ConsistentSamplingUtil::calculateThreshold()`
computes `MAX_THRESHOLD − (int) round(p · MAX_THRESHOLD)`. The multiplication is
exact: `2^56` is a power of two, so `p · 2^56` is the exact IEEE-754 product.
`round()` is what moves the result, because it perturbs an already-integral
double whose spacing is one or two units. At a ratio of `0.0625` the product is
already integral — `2^52` — so the rounded value is `2^52 + 1` and the threshold
comes out `67553994410557439`, written `th:efffffffffffff`. The definition
requires `15 · 2^52` = 67553994410557440
(`tracestate-probability-sampling.md:98-100`,
`trace-tracestate-handling.md:129-140`), which the specification's own table
prints as `th:f` (`:440-454`). The *form* the SDK writes is legal — `th` accepts
1 to 14 hexadecimal digits, trailing zeros optional
(`trace-tracestate-handling.md:110`, `:457-470`) — the number it denotes is one
unit low. Every ratio this test configures has an exactly-defined threshold, so
this failure is a deviation from the **normative** definition rather than a
matter of precision choice, and it is confined: probed through
`ConsistentSamplingUtil::calculateThreshold()`, `round()` moves every even integer
in the band `[2^52, 2^53)` by one and leaves every odd integer and every integer
below `2^52` alone, and a dyadic ratio produces a product in that band exactly
when it lies in `[1/16, 1/8)` — so `1/16`, `3/32`, `5/64`, `7/64`, `9/128`,
`17/256` and the rest of that family are each one unit low, while the ratios this
same test configures at `1/8` and above (`0.125`, `0.25`, `0.5`) and every power
of two below `1/16` come out exact. The same mechanism also produces the
one-unit-low value in the next footnote's `th:e6666666666665` (`0.1` is inside
that interval). tbachert reproduces `th:f` with no such deviation. Reported
upstream as [#2135](https://github.com/open-telemetry/opentelemetry-php/issues/2135),
together with footnote ²⁶.  
²⁶ **`open-telemetry/sdk` writes rejection thresholds at full precision instead
of the recommended 4 digits (1 test).** The test this footnote covers measures
conformance to a **recommendation**: "OpenTelemetry SDKs are recommended to use 4
digits of precision by default" (`tracestate-probability-sampling.md:435`), a
sentence inside the non-normative "## Algorithms" section. What the same document
normatively requires is the threshold definition
`Rejection_Threshold = (1 - Sampling_Probability) * 2^56` (`:98-100`) and the
encoding of that value (`:457-470`), and `open-telemetry/sdk` reports the value
its computation produces for both: `ConsistentSamplingUtil::encodeThreshold()`,
called from `OtelTraceState` when it writes the `th:` entry, emits the threshold
as lowercase hexadecimal with trailing zeros stripped (`0` for a zero threshold).
It is **not** a violation of the threshold definition; it is a 13-to-14-
significant-digit threshold where the recommended form has four significant
digits: `th:aaaaaaaaaaaaac` for 1-in-3 instead of `th:aaab`,
`th:cccccccccccccc` for 1-in-5 instead of `th:cccd`, `th:e6666666666665` for
1-in-10 instead of `th:e666`, `th:fd70a3d70a3d71` for 1-in-100 instead of
`th:fd70a`, `th:ffffef39085f4a` for 1-in-1000000 instead of `th:ffffef39`. Each
of those is the nearest integer to the defined product for the ratio the
configuration parses to. Converting each form back with "Converting threshold to
a sampling probability" (`:478-486`) shows what the recommendation costs:
`th:aaab` stands for 0.3333282470703125, the "Actual probability (precision 4)"
column of the same table row, while `th:aaaaaaaaaaaaac` stands for
0.33333333333333326 — the configured ratio itself. The two SDKs agree on every
ratio whose threshold is a single digit except 1-in-16, which is footnote ²⁵;
from 1-in-10 downward the difference is this one. The third test in that cell
covers the smallest ratio the range allows, `2^-56` (`:84`), and asserts the
value the threshold definition requires there — `2^56 - 1`, written
`th:ffffffffffffff`, which is what both SDKs write — rather than the value the
specification's reference algorithm produces. That algorithm yields
`th:ffffffffffff` at this ratio: its `min(12, …)` precision clamp (`:398`) and
the `rejection_prob >= 2.0` fallback (`:411-412`) leave it twelve digits and
thereby zero the low 8 bits of the threshold, so read back per
`trace-tracestate-handling.md:116-117` any ratio below `2^-48` is encoded as
`2^-48`, up to 256 times the configured rate. The same pseudo-code makes no
symmetrical adjustment near unit probability "as we do not believe there is a
practical use for sampling very precisely near 100%" (`:428`), so at the
recommended four digits a ratio of about `0.9999999` or nearer to 1 cannot be
told apart from sampling everything and is encoded `th:0`; tbachert does exactly
that, `open-telemetry/sdk` writes a threshold nearer to the definition, and no
test in this suite asserts thresholds that close to 1. Both are artifacts of that
pseudo-code, and this suite documents them instead of asserting them. The
consistent-sampling code these tests exercise was added by
the merged [PR #2077](https://github.com/open-telemetry/opentelemetry-php/pull/2077).
Reported upstream as [#2135](https://github.com/open-telemetry/opentelemetry-php/issues/2135),
which covers this precision default together with the rounding defect of
footnote ²⁵.  

## Upstream tracking (official SDK failures, by root cause)

### `open-telemetry/sdk` — 108 failing spec tests

Grouped by the defect they measure. Of these, 12 are
errors raised before the SDK was built (all Prometheus) and 96 are assertion
failures.

| Failing group | Tests | Tracking |
|---|---:|---|
| Periodic `MetricReader` never repeats a collection: interval ignored, delta temporality and cumulative start timestamps unobservable, multi-collection pipeline behavior unobservable (12 `async`-group tests) | 12 | [#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884) (open) |
| Prometheus exporter: no provider for the `pull` meter-reader type (11 configuration tests), no env exporter factory for `prometheus` (1 env test) | 12 | not reported upstream (by decision) |
| Invalid environment values are not tolerated: unrecognized or differently-cased sampler, protocol and exporter identifiers abort initialization (5 tests), as do unparseable numeric values — an invalid `OTEL_TRACES_SAMPLER_ARG` in two settings and `OTEL_ATTRIBUTE_COUNT_LIMIT` (3 tests); a malformed entry in `OTEL_RESOURCE_ATTRIBUTES` discards the valid entries in the same list, and a differently-cased `OTEL_PROPAGATORS` list drops an entry (2 tests) | 10 | unrecognized enum values: [#2137](https://github.com/open-telemetry/opentelemetry-php/issues/2137) (open); unparseable numeric values: [#2136](https://github.com/open-telemetry/opentelemetry-php/issues/2136) (open); case-insensitive interpretation of enum values: [#2138](https://github.com/open-telemetry/opentelemetry-php/issues/2138) (open); the `OTEL_RESOURCE_ATTRIBUTES` list handling untracked |
| Configuration nodes absent from the implemented data model 1.0 are rejected by its schema — 1.2-only: `view_matching_mode/development` (6), `max_request_size`/`max_response_size` (7), `attribute_value_depth_limit` (3), `always_record` (1); where the 1.0 schema has no such node and data model 1.1 introduces it: `max_export_batch_size` under a periodic metric reader (2), `id_generator` (1), `event_to_span_event_bridge/development` (1). Only the metric placement of `max_export_batch_size` is newer: it is a 1.0 node of the batch log record and batch span processors, and those tests pass on both SDKs | 21 | newer data model targeted by [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050) (draft, partially superseded by the merged [PR #2068](https://github.com/open-telemetry/opentelemetry-php/pull/2068)); the individual nodes untracked |
| Cardinality limits parsed but not applied: no overflow series, no per-instrument-type limits | 9 | untracked |
| `jaeger_remote` sampler not implemented (5 env, 3 configuration) | 8 | untracked |
| `OTEL_ENTITIES` env detector not implemented (7 env, 1 configuration) | 8 | untracked |
| `logger_configurator` `minimum_severity` and `trace_based` parsed but not applied | 6 | untracked |
| Exemplars not captured; an invalid filter value falls back to no exemplars instead of `trace_based` | 4 | values fixed by the merged [PR #2057](https://github.com/open-telemetry/opentelemetry-php/pull/2057); the invalid `OTEL_METRICS_EXEMPLAR_FILTER` value not falling back to the default is reported as [#2137](https://github.com/open-telemetry/opentelemetry-php/issues/2137) (open); capturing exemplars is untracked |
| Default histogram aggregation not applied (env var ignored, `default_histogram_aggregation` inert, base2 defaults not produced) | 4 | untracked |
| `ProbabilitySampler` emits no TraceID-randomness compatibility warning when the Trace random flag is unset, although the `TraceIdRatioBased`-as-child warning is emitted (1 configuration test, footnote ⁴) | 1 | untracked |
| `probability/development` writes a rejection threshold one unit below the value the definition requires for 1-in-16: `th:efffffffffffff` = 67553994410557439, where `15 · 2^52` = 67553994410557440 is required and the specification's own table prints `th:f` (1 configuration test, footnote ²⁵). This is a deviation from the **normative** definition (`tracestate-probability-sampling.md:98-100`, `trace-tracestate-handling.md:129-140`), not a precision or formatting choice: `p · 2^56` is the exact product because `2^56` is a power of two, and `round()` moves an already-integral product wherever a double of that magnitude is spaced by one or two units. The written form is a legal encoding; the number it denotes is one unit low | 1 | consistent samplers implemented by the merged [PR #2077](https://github.com/open-telemetry/opentelemetry-php/pull/2077); the off-by-one is reported as [#2135](https://github.com/open-telemetry/opentelemetry-php/issues/2135) (open) |
| `probability/development` writes its rejection threshold with every digit it computes instead of the recommended 4 significant digits: `th:aaaaaaaaaaaaac` for 1-in-3, `th:cccccccccccccc` for 1-in-5, `th:e6666666666665` for 1-in-10, `th:fd70a3d70a3d71` for 1-in-100, `th:ffffef39085f4a` for 1-in-1000000 (1 configuration test, footnote ²⁶). This row measures conformance to a **recommendation** — `tracestate-probability-sampling.md:435` — and not a defect in the threshold value: each of those values is the nearest integer to `MaxAdjustedCount * (1 - Probability)` with `MaxAdjustedCount = 2^56` (`:98-100`, `trace-tracestate-handling.md:129-140`) for the ratio the configuration parses to, and a 13-to-14-digit value is a legal encoding: `th` accepts between 1 and 14 lowercase hexadecimal digits (`trace-tracestate-handling.md:110`) with trailing zeros optional (`:457-470`) | 1 | consistent samplers implemented by the merged [PR #2077](https://github.com/open-telemetry/opentelemetry-php/pull/2077); the precision default is reported as [#2135](https://github.com/open-telemetry/opentelemetry-php/issues/2135) (open), the same issue as the row above |
| Resource detectors in configuration mode: container detector, `service.instance.id` from the service detector, conflicting `schema_url` | 3 | untracked |
| View aggregation overrides ignored (base2 histogram from a view, incompatible aggregation not dropped) | 2 | untracked |
| Batch span / batch log record processors flush synchronously, so a full queue and its drop cannot be observed | 2 | untracked |
| `OTEL_LOG_LEVEL` in configuration mode: the configured level applies to component-creation diagnostics only, so an SDK-internal span-limit diagnostic survives `log_level: error` | 1 | untracked |
| Accepted but unenforced limits: log record attribute count/length limits, and the default attribute value-depth limit (whose asserted value, 64, is itself specified only in data model 1.2) | 2 | untracked |
| An explicit `meter_provider.views: null` is accepted where the suite asserts rejection; the suite pins the strict reading of the model's nullability encoding (1 configuration test, footnote ²³) | 1 | no confirmed defect; to be raised upstream as a question about `null` on a non-required `array`-typed node, not reported upstream |
| **Total** | **108** | |

Caveats:

- 21 of the failures measure the *missing newer data model*, not a broken
  feature: those nodes are not part of the 1.0 schema the SDK implements, so the
  configuration is rejected before the feature can run. They are counted as
  failures because the suite describes the current configuration surface; they
  can flip to passing without any other SDK change once the newer model is
  implemented.
- 12 failures come from a single upstream defect (#1884) and land in several
  rows of the matrix (metric pipeline, temporality, collection interval). Each
  test is counted in exactly one cell.
- 1 failure measures a *reading* of the configuration model rather than a defect
  (footnote ²³): a present-but-null `views` has support in both the type-union
  encoding and the `defaultBehavior` MUST, so that row is listed as an open
  question about the data model and not as a gap attributed to either SDK.
- 1 failure measures conformance to a **recommendation**, not to a normative
  requirement: the official test that compares the written rejection threshold
  with the recommended four significant digits (footnote ²⁶). "OpenTelemetry SDKs
  are recommended to use 4 digits of precision by default"
  (`tracestate-probability-sampling.md:435`) is a recommendation inside the
  section headed "## Algorithms", while
  `Rejection_Threshold = (1 - Sampling_Probability) * 2^56` (`:98-100`) and the
  hexadecimal / trailing-zero / `th:0` encoding rules (`:457-470`) are
  normative. The other threshold failure is the opposite case: one unit below a
  threshold the definition fixes exactly (footnote ²⁵). Neither is comparable to
  the feature gaps above.
- 🟨 cells are counted as failing in the run totals. They occur in the official
  columns only, and `--exclude-group async` turns them green because every test
  failing in them is an `async`-group test; what they measure is the official
  SDK's periodic reader (footnote ⁹), not a scheduling artifact. The marker is
  maintained by hand from the JUnit failure list plus the tests'
  `#[Group('async')]` attributes: `check-matrix.py` verifies per-cell pass/fail
  counts and env/config-file membership, not the marker.
- The `file_format "1.2" is newer than 1.0` notice is emitted by every
  configuration-file run. It is not the cause of the `OTEL_LOG_LEVEL` failure
  (footnote ²²): at `log_level: error` that notice is suppressed. It appears
  inside one captured failure message only as the *only* diagnostic in it, i.e.
  as evidence that no other warning was emitted. It is also the baseline the
  sampler-warning tests cancel with a same-configuration control (footnote ⁴),
  and it is only a log statement: it filters no configuration node, which probes
  confirmed for the 1.2 `probability/development` node it accompanies.
- "Untracked" items are genuine spec-scope gaps identified by this suite; they
  are not reported upstream yet.
- Timing-sensitive tests (collection intervals against async delays,
  `Retry-After` windows) flake under concurrent host load. Run one suite at a
  time; a single extra failure that disappears on a clean re-run is a flake, not
  a matrix change.

## Deliberate deviation pins

- `OTEL_RESOURCE_ATTRIBUTES` percent-decodes values but not keys in **both**
  SDKs, although a strict reading of the spec implies decoding both. Cross-SDK
  check (2026-09-17): Go, Java, Python and .NET also decode values only — only
  the JS SDK decodes keys. The suite therefore pins the de facto cross-language
  behavior (`EnvResourceTest::testResourceAttributesDecodeValuesButNotKeys`);
  a single-SDK "fix" would break parity with the other six implementations.

# SDK support overview

Feature-by-feature pass/fail status of the suite's shared `spec` group — every
test that verifies specification-defined behavior — against the two SDKs under
`sdks/`. Generated from full suite runs (JUnit logs); regenerate with
`make test-all`, which writes `.junit-<sdk>.xml` for both SDKs.

- **tbachert run:** 420 tests executed, **420 passing** (`spec` + `tbachert`
  groups).
- **official run:** 422 tests executed, **316 passing / 106 failing**
  (`spec` + `official` groups). All 106 failures are shared spec tests: 29 in
  environment-variable mode, 77 in configuration-file mode. As the JUnit log
  splits them: **12 errors, 94 assertion failures**. All 12 errors are the
  Prometheus exporter tests (11 configuration-file, 1 environment-variable),
  which fail before the SDK is built; every other failure is an assertion about
  exported data or emitted output.

The matrix covers the 416 shared `spec` tests (160 environment-variable mode,
256 configuration-file mode per SDK); vendor-specific tests
(`TbachertSpecificTest`, `OfficialSpecificTest`) are documented in the README
and are not part of this overview. On the official SDK that means 132/160
env-mode and 178/256 config-file-mode spec tests pass. Sections follow the
specification's structure (context propagation, then the signals in spec order:
traces, metrics, logs); rows within a section are organized by tested behavior.
Since the configuration file must support at least every option available via
environment variables, each env-based row has a config-file counterpart; rows
missing one side cover either data-model nodes without an environment
equivalent (config-only) or environment-value parsing semantics that have no
config-file equivalent (env-only).

Legend: ✅ all passing · 🟨 only `async`-group tests failing (passing once the
`async` tests are excluded) · ⚠️ partially passing · ❌ fully failing · – no tests
for that mode. Each spec test is counted in exactly one cell; the "Upstream
tracking" table at the bottom accounts for all 106 failures by root cause.

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
| Sampling: always_on/off, trace-id ratio, parent-based (simple samplers) | ✅ 11/11 | ✅ 11/11 | ⚠️ 9/11¹ | ✅ 11/11 |
| Composite & rule-based samplers (`rule_based`, `probability`, `parent_threshold`, `always_record`) | – | ✅ 22/22 | – | ⚠️ 19/22³ |
| `jaeger_remote` sampler (remote dial, strategies, initial sampler) | ✅ 5/5 | ✅ 3/3 | ❌ 0/5⁴ | ❌ 0/3⁴ |
| Span & attribute limits (count, value length, depth) | ✅ 11/11 | ✅ 6/6 | ⚠️ 10/11¹ | ⚠️ 3/6⁵ |
| Batch & simple span processors | ✅ 5/5 | ✅ 5/5 | ⚠️ 4/5⁶ | ✅ 5/5 |
| Span details: status, events, kinds, links, attribute value types | ✅ 5/5 | ✅ 1/1 | ✅ 5/5 | ✅ 1/1 |

<details>
<summary>End-to-end span export & cross-service context propagation (incl. mixed env/config-file services) — 3 tests (1 env · 2 config)</summary>

- `ConfigBasicTest::testConfigFileConfiguresTracerProvider` — tbachert ✅ · official ✅
- `EnvEndToEndTest::testTraceContextPropagatesAcrossServices` — tbachert ✅ · official ✅
- `EnvEndToEndTest::testTraceContextPropagatesBetweenEnvAndConfigFileModes` — tbachert ✅ · official ✅

</details>

<details>
<summary>Sampling: always_on/off, trace-id ratio, parent-based (simple samplers) — 22 tests (11 env · 11 config)</summary>

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
<summary>Composite & rule-based samplers (`rule_based`, `probability`, `parent_threshold`, `always_record`) — 22 tests (0 env · 22 config)</summary>

- `ConfigProbabilitySamplerTest::testComposableProbabilitySampler` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerConsistentAcrossThresholds` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerDefaultsToRatioOne` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerUsesPropagatedExplicitRandomness` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerWritesThresholdTraceState` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testParentThresholdSamplerFollowsSampledRemoteParent` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testParentThresholdSamplerInheritsRemoteParentThreshold` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testProbabilitySamplerIgnoresInvalidExplicitRandomness` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testProbabilitySamplerPreservesPropagatedExplicitRandomness` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerThresholdMatchesSamplingDecisions` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerTraceStatePropagatesToChildSpans` — tbachert ✅ · official ✅
- `ConfigProbabilitySamplerTest::testProbabilitySamplerWritesThresholdTraceState` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testAlwaysRecordSamplerRecordsWithoutReporting` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testCompositeAlwaysOffDropsAllSpans` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testCompositeAlwaysOnSamplesAllSpans` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerExcludedAttributePatterns` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerMatchesAttributePatterns` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerMatchesLocalParentOrigin` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerMatchesParentOrigin` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerRoutesByRemainingSpanKinds` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerRoutesBySpanKindAndAttributes` — tbachert ✅ · official ✅
- `ConfigSamplingTest::testRuleBasedSamplerWithoutRulesDropsAllSpans` — tbachert ✅ · official ✅

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
| Metric pipeline: instruments, series separation, gauge/counter semantics | – | ✅ 8/8 | – | 🟨 2/8⁷⁸ |
| Exemplar filter (always_on/off, trace_based, invalid fallback) | ✅ 4/4 | ✅ 4/4 | ⚠️ 3/4⁹ | ⚠️ 1/4⁹ |
| Temporality preference (cumulative/delta/lowmemory) | ✅ 2/2 | ✅ 5/5 | 🟨 1/2⁷⁸ | 🟨 1/5⁷⁸ |
| Default histogram aggregation (exponential buckets, spec boundaries) | ✅ 1/1 | ✅ 4/4 | ❌ 0/1¹⁰ | ⚠️ 1/4¹⁰ |
| Collection interval & periodic reader (batch size) | ✅ 1/1 | ✅ 3/3 | 🟨 0/1⁷⁸ | ⚠️ 1/3¹¹ |
| Views: instrument & meter selection (incl. wildcards) | – | ✅ 14/14 | – | ✅ 14/14 |
| Views: attribute key filtering (include/exclude, precedence) | – | ✅ 4/4 | – | ✅ 4/4 |
| Views: aggregation overrides (histogram buckets, drop, sum/last-value) | – | ✅ 7/7 | – | ⚠️ 5/7¹² |
| Views: metric renaming & description | – | ✅ 4/4 | – | ✅ 4/4 |
| Views: matching behavior (no-match, multiple streams, ordering) | – | ✅ 5/5 | – | ✅ 5/5 |
| Composable views (same-name merging, unnamed join, application order) | – | ✅ 6/6 | – | ❌ 0/6¹³ |
| Cardinality limits (overflow series) | – | ✅ 10/10 | – | ⚠️ 1/10¹⁴ |

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
| Log record limits (attribute count & value length) | ✅ 3/3 | ✅ 2/2 | ✅ 3/3 | ❌ 0/2⁵ |
| Batch & simple log record processors | ✅ 5/5 | ✅ 3/3 | ⚠️ 4/5⁶ | ✅ 3/3 |
| Log severity threshold & trace-based filtering | – | ✅ 7/7 | – | ⚠️ 1/7¹⁵ |

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
| Entities (`OTEL_ENTITIES`, env detector) | ✅ 7/7 | ✅ 1/1 | ❌ 0/7¹⁶ | ❌ 0/1¹⁶ |
| Resource detectors & attribute include/exclude (config file) | – | ✅ 11/11 | – | ⚠️ 8/11¹⁷ |
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
| OTLP HTTP exporter options: protocol, headers, compression, endpoints, timeouts, size limits, retries | ✅ 24/24 | ✅ 23/23 | ⚠️ 22/24¹ | ⚠️ 17/23¹⁸ |
| OTLP gRPC exporter (all signals) | ✅ 12/12 | ✅ 4/4 | ✅ 12/12 | ✅ 4/4 |
| OTLP file exporter (newline-delimited JSON on disk) | – | ✅ 3/3 | – | ✅ 3/3 |
| TLS: CA trust & client certificates (all signals, both protocols) | ✅ 11/11 | ✅ 6/6 | ✅ 11/11 | ✅ 6/6 |
| Console exporter (stdout) | ✅ 3/3 | ✅ 3/3 | ✅ 3/3 | ✅ 3/3 |
| Prometheus exporter (pull, translation & escaping) | ✅ 1/1 | ✅ 11/11 | ❌ 0/1¹⁹ | ❌ 0/11¹⁹ |

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
| `OTEL_LOG_LEVEL` (self-diagnostic output) | ✅ 1/1 | ✅ 2/2 | ✅ 1/1 | ⚠️ 1/2²⁰ |
| Config file basics: no-op SDK, format versioning, missing file, independent signals | – | ✅ 4/4 | – | ✅ 4/4 |
| ID generation (random) | – | ✅ 1/1 | – | ❌ 0/1²¹ |
| Variable substitution: `${}`, defaults, escaping, type coercion | – | ✅ 9/9 | – | ⚠️ 8/9¹⁸ |
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
Untracked upstream.  
² **No log-record-to-span-event bridge in configuration mode (1 test).** A
configuration that installs the bridge processor fails initialization: the
corresponding component provider is not registered. Untracked upstream.  
³ **Composite samplers in configuration mode (3 tests).** `always_record` has no
provider, so a configuration using it fails initialization (`component
"opentelemetry\sdk\trace\samplerinterface" uses unknown provider
"always_record", available providers are "always_off", "always_on",
"composite/development", "parent_based", "probability/development",
"trace_id_ratio_based"`). The other two tests measured a sampling *decision*
official got right, and each fails on its last assertion only:
`testProbabilitySamplerIgnoresInvalidExplicitRandomness` ignored the invalid
explicit randomness (the span was sampled from the trace ID's own bits and
carried `th:8`) and failed on `Failed asserting that an array has the key
'rv:0123456789abcdef'.` — the unrecognized `ot` entry is dropped instead of
passed through, and its "Invalid TraceState.ot rv value" diagnostic is never
reported; `testParentThresholdSamplerInheritsRemoteParentThreshold` inherited
the consistent parent's `th:c`, ignored the inconsistent parent's threshold and
kept the parent's sampled flag, and failed on `Expected a warning about the
sampling threshold / sampled flag inconsistency.` because no such diagnostic is
emitted at all. Whether an SDK must preserve an unrecognized `ot` entry and must
diagnose an inconsistent threshold/flag combination is not settled by the
specification text this suite cites; these are open questions, not confirmed
SDK gaps. Untracked upstream.  
⁴ **`jaeger_remote` is not implemented (8 tests: 5 env, 3 configuration).**
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
⁵ **Attribute and log-record limits in configuration mode (5 tests).** Three of
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
⁶ **Queue-full behavior cannot be observed (2 tests).** The batch span processor
and the batch log record processor flush synchronously when the queue is full,
so the queue never overflows and nothing is dropped. Untracked upstream.  
⁷ **The periodic exporting `MetricReader` does not repeat collections (12
tests).** The export interval is not used by the reader, so only a single
collection happens at shutdown: multi-collection pipeline behavior (series
separation, gauge/observable instruments across collections), delta temporality,
cumulative start timestamps and interval-controlled collection all become
unobservable. Issue
[#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884)
("Periodic exporting MetricReader not working", open).  
⁸ **Cells marked 🟨:** every failing test in such a cell belongs to the `async`
group, so the cell turns green when those tests are excluded
(`--exclude-group async`). They occur in the official columns only — the tbachert
run passes the same tests — and they are counted in the run totals like any
other failure. Their cause is footnote ⁷.  
⁹ **Exemplars are not captured (4 tests).** The filter *values* are accepted
since [PR #2057](https://github.com/open-telemetry/opentelemetry-php/pull/2057)
(closed issue
[#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054)), but
no exemplars are exported for `always_on` or
`trace_based`, and an invalid filter value falls back to no exemplars instead of
the default `trace_based`. Untracked upstream.  
¹⁰ **Default histogram aggregation is not applied (4 tests).**
`OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION` is ignored, and the
`default_histogram_aggregation` node "parses but is not applied yet" per the
configuration package's changelog; `base2_exponential_bucket_histogram` parses, then
falls back to the reader default, so the spec's default boundaries and scales
are never produced. Untracked upstream.  
¹¹ **`max_export_batch_size` on a periodic reader (2 tests).** The node exists
only in data model 1.2, so the configuration is rejected before the reader is
built.  
¹² **View aggregation overrides are not honored (2 tests).** A view selecting a
base2 exponential histogram does not produce the expected metric stream, and an
aggregation incompatible with the instrument kind is not ignored. Untracked
upstream.  
¹³ **Composable views (6 tests).** `view_matching_mode/development` exists only
in data model 1.2, so every configuration using composable views is rejected at
initialization.  
¹⁴ **Cardinality limits are not applied (9 tests).** Per the configuration
package's changelog,
"Cardinality limits are not applied yet": reader and view cardinality limits
produce no overflow series and are not enforced per instrument type. Untracked
upstream.  
¹⁵ **`logger_configurator` severity and trace filters are inert (6 tests).**
`minimum_severity` and `trace_based` are parsed but, per the configuration
package's changelog,
"not applied yet": logs below the threshold and records from unsampled traces
are exported. Untracked upstream.  
¹⁶ **`OTEL_ENTITIES` is not implemented (8 tests).** The environment entity
detector does not read `OTEL_ENTITIES` — not for the plain form, duplicates,
conflicting identities, malformed definitions, schema-url handling or percent
decoding — and the configuration-file env detector reports no entities either.
Untracked upstream.  
¹⁷ **Resource detectors in configuration mode (3 tests).** The container detector
reports no container id (`Resource attribute "container.id" was not found.`);
the service detector does read `service.name` from `OTEL_SERVICE_NAME` — that
assertion passed — and what is missing is the instance id it also specifies
(`Resource attribute "service.instance.id" was not found.`); and a resource
`schema_url` conflicting with detected resources is not dropped
(`Failed asserting that two arrays are identical.`, with the conflicting
`https://example.com/schemas/custom-1.0` still present). Untracked upstream.  
¹⁸ **OTLP request/response size limits (7 tests).** `max_request_size` and
`max_response_size` on exporter configuration nodes exist only in data model
1.2, so the six configurations (traces, logs, metrics) are rejected
(`unrecognized option "max_request_size" under "otlp_http". available options
are "compression", "encoding", "endpoint", "headers", "headers_list",
"timeout", "tls"`). The seventh test is the one that substitutes a hexadecimal
value into such a node; it fails on that same unknown node
(`invalid configuration for path "batch.exporter": unrecognized option
"max_request_size" under "otlp_http"`), not on coercing the substituted value.  
¹⁹ **Prometheus exporter is not available (12 tests).** In configuration mode
the pull-type meter reader itself has no provider: `Component
"OpenTelemetry\SDK\Metrics\MetricReaderInterface" uses unknown provider "pull",
available providers are "periodic"` (11 tests). In environment mode the exporter
selection fails with "Metric exporter factory not registered for protocol:
prometheus" (1 test). A pull exporter additionally needs an async runtime to
serve the reader. Not reported upstream (by decision).  
²⁰ **`OTEL_LOG_LEVEL` diagnostics in configuration mode (1 test).** Only one of
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
²¹ **`id_generator` (1 test).** The node exists only in data model 1.2, so the
configuration is rejected before the SDK is built.  

## Upstream tracking (official SDK failures, by root cause)

106 failing spec tests, grouped by the defect they measure. Of these, 12 are
errors raised before the SDK was built (all Prometheus) and 94 are assertion
failures.

| Failing group | Tests | Tracking |
|---|---:|---|
| Periodic `MetricReader` never repeats a collection: interval ignored, delta temporality and cumulative start timestamps unobservable, multi-collection pipeline behavior unobservable (12 `async`-group tests) | 12 | [#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884) (open) |
| Prometheus exporter: no provider for the `pull` meter-reader type (11 configuration tests), no env exporter factory for `prometheus` (1 env test) | 12 | not reported upstream (by decision) |
| Invalid environment values are not tolerated: case-sensitive sampler/protocol/exporter identifiers, unparseable numeric limits and an invalid `OTEL_TRACES_SAMPLER_ARG` abort initialization (8 tests); a malformed `OTEL_RESOURCE_ATTRIBUTES` entry and an invalid `OTEL_PROPAGATORS` entry are dropped without aborting initialization (2 tests) | 10 | untracked |
| Configuration nodes that exist only in data model 1.2 are rejected by the implemented 1.0 schema: `view_matching_mode/development` (6), `max_request_size`/`max_response_size` (7), `attribute_value_depth_limit` (3), `max_export_batch_size` under a periodic reader (2), `id_generator` (1) | 19 | newer data model targeted by [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050) (draft, partially superseded by the merged [PR #2068](https://github.com/open-telemetry/opentelemetry-php/pull/2068)); the individual nodes untracked |
| Cardinality limits parsed but not applied: no overflow series, no per-instrument-type limits | 9 | untracked |
| `jaeger_remote` sampler not implemented (5 env, 3 configuration) | 8 | untracked |
| `OTEL_ENTITIES` env detector not implemented (7 env, 1 configuration) | 8 | untracked |
| `logger_configurator` `minimum_severity` and `trace_based` parsed but not applied | 6 | untracked |
| Exemplars not captured; an invalid filter value falls back to no exemplars instead of `trace_based` | 4 | values fixed by the merged [PR #2057](https://github.com/open-telemetry/opentelemetry-php/pull/2057); capture and fallback untracked |
| Default histogram aggregation not applied (env var ignored, `default_histogram_aggregation` inert, base2 defaults not produced) | 4 | untracked |
| Composite samplers in configuration mode: `always_record` provider missing; the invalid explicit `probability` randomness *is* ignored, but the unrecognized `rv:` entry is dropped instead of passed through and no threshold/flag diagnostic is emitted | 3 | untracked |
| Resource detectors in configuration mode: container detector, `service.instance.id` from the service detector, conflicting `schema_url` | 3 | untracked |
| View aggregation overrides ignored (base2 histogram from a view, incompatible aggregation not dropped) | 2 | untracked |
| Batch span / batch log record processors flush synchronously, so a full queue and its drop cannot be observed | 2 | untracked |
| `OTEL_LOG_LEVEL` in configuration mode: the configured level applies to component-creation diagnostics only, so an SDK-internal span-limit diagnostic survives `log_level: error` | 1 | untracked |
| Accepted but unenforced limits: log record attribute count/length limits, and the default attribute value-depth limit (whose asserted value, 64, is itself specified only in data model 1.2) | 2 | untracked |
| Log-record-to-span-event bridge processor has no component provider in configuration mode | 1 | untracked |
| **Total** | **106** | |

Caveats:

- 19 of the failures measure the *missing newer data model*, not a broken
  feature: those nodes are not part of the 1.0 schema the SDK implements, so the
  configuration is rejected before the feature can run. They are counted as
  failures because the suite describes the current configuration surface; they
  can flip to passing without any other SDK change once the newer model is
  implemented.
- 12 failures come from a single upstream defect (#1884) and land in several
  rows of the matrix (metric pipeline, temporality, collection interval). Each
  test is counted in exactly one cell.
- 🟨 cells are counted as failing in the run totals. They occur in the official
  columns only, and `--exclude-group async` turns them green because every test
  failing in them is an `async`-group test; what they measure is the official
  SDK's periodic reader (footnote ⁷), not a scheduling artifact. The marker is
  maintained by hand from the JUnit failure list plus the tests'
  `#[Group('async')]` attributes: `check-matrix.py` verifies per-cell pass/fail
  counts and env/config-file membership, not the marker.
- The `file_format "1.2" is newer than 1.0` notice is emitted by every
  configuration-file run. It is not the cause of the `OTEL_LOG_LEVEL` failure
  (footnote ²⁰): at `log_level: error` that notice is suppressed. It appears
  inside one captured failure message only as the *only* diagnostic in it, i.e.
  as evidence that no other warning was emitted.
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

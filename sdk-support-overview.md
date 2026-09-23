# SDK support overview

Feature-by-feature pass/fail status of the suite's shared `spec` group — every
test that verifies specification-defined behavior — against the two SDKs under
`sdks/`. Generated from full suite runs (JUnit logs); regenerate with
`make test-all`, which writes `.junit-<sdk>.xml` for both SDKs.

- **tbachert run:** 378 tests executed, **378 passing** (`spec` + `tbachert`
  groups).
- **official run:** 380 tests executed, **108 passing / 272 failing**
  (`spec` + `official` groups). Most failures (210 of 272) are gated by
  not-yet-updated config-file support: the SDK only accepts
  `file_format: '1.0-rc.2'`, while the suite uses data model version 1.2, so
  every config-file test fails at initialization.

The matrix below covers the 374 shared `spec` tests; vendor-specific tests
(`TbachertSpecificTest`, `OfficialSpecificTest`) are documented in the README
and not part of this overview. Sections follow the specification's structure
(context propagation, then the signals in spec order: traces, metrics, logs);
rows within a section are organized by tested behavior. Since the
configuration file must support at least every option available via
environment variables, each env-based row has a config-file counterpart; rows
missing one side cover either data-model nodes without an environment
equivalent (config-only) or environment-value parsing semantics that have no
config-file equivalent (env-only).

Legend: ✅ all passing · 🟨 only `async`-group tests failing (passing once the
`async` tests are excluded) · ⚠️ partially passing · ❌ fully failing · – no tests
for that mode. Each spec test is counted in exactly one cell; the "Upstream
tracking" table at the bottom accounts for all 272 failures by root cause.

Accounting is machine-checkable: `matrix-map.json` assigns every spec test to
exactly one row, and `python3 check-matrix.py` verifies that assignment against
the JUnit logs (row membership, per-cell pass/fail counts, column totals).
The collapsed list under each table repeats the same tests individually with
per-SDK markers.
Footnotes are collected below the tables.

## Context propagation

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Propagators: tracecontext, baggage, b3 (injection & extraction) | ✅ 17/17 | ✅ 7/7 | ⚠️ 16/17¹ | ❌ 0/7³ |
| Cross-signal correlation: logs & metrics carry the active span context | ✅ 4/4 | ✅ 1/1 | ⚠️ 2/4² | ❌ 0/1³ |

<details>
<summary>Propagators: tracecontext, baggage, b3 (injection & extraction) — 24 tests (17 env · 7 config)</summary>

- `ConfigPropagatorTest::testConfigFileCanConfigureOnlyTraceContextPropagator` — tbachert ✅ · official ❌
- `ConfigPropagatorTest::testConfigFileCompositeListConfiguresPropagators` — tbachert ✅ · official ❌
- `ConfigPropagatorTest::testConfigFilePropagatorB3CompositeInjectsSingleHeader` — tbachert ✅ · official ❌
- `ConfigPropagatorTest::testConfigFilePropagatorB3MultiCompositeInjectsHeaders` — tbachert ✅ · official ❌
- `ConfigPropagatorTest::testConfigFilePropagatorsAreUsedForInjection` — tbachert ✅ · official ❌
- `ConfigPropagatorTest::testConfigFilePropagatorsConfigureTraceContextAndBaggage` — tbachert ✅ · official ❌
- `ConfigPropagatorTest::testConfigFileWithoutPropagatorInjectsNothing` — tbachert ✅ · official ❌
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
- `EnvCorrelationTest::testAllSignalsCorrelateWithinOneTrace` — tbachert ✅ · official ❌
- `EnvCorrelationTest::testLogRecordEmittedInSpanCarriesTraceContext` — tbachert ✅ · official ✅
- `EnvCorrelationTest::testLogRecordInNestedSpanReferencesInnerSpan` — tbachert ✅ · official ✅
- `EnvCorrelationTest::testMetricsExemplarCarriesActiveSpanContext` — tbachert ✅ · official ❌

</details>

## Traces

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| End-to-end span export & cross-service context propagation (incl. mixed env/config-file services) | ✅ 1/1 | ✅ 2/2 | ✅ 1/1 | ❌ 0/2³ |
| Sampling: always_on/off, trace-id ratio, parent-based (simple samplers) | ✅ 11/11 | ✅ 9/9 | ⚠️ 9/11¹ | ❌ 0/9³ |
| Composite & rule-based samplers (`rule_based`, `probability`, `parent_threshold`, `always_record`) | – | ✅ 18/18 | – | ❌ 0/18³ |
| `jaeger_remote` sampler (remote dial, strategies, initial sampler) | ✅ 5/5⁴ | ✅ 2/2⁴ | ❌ 0/5⁴ | ❌ 0/2³⁴ |
| Span & attribute limits (count, value length, depth) | ✅ 11/11 | ✅ 4/4 | ⚠️ 8/11¹⁵ | ❌ 0/4³ |
| Batch & simple span processors | ✅ 5/5 | ✅ 5/5 | ⚠️ 4/5⁶ | ❌ 0/5³ |
| Span details: status, events, kinds, links, attribute value types | ✅ 5/5 | ✅ 1/1 | ✅ 5/5 | ❌ 0/1³ |

<details>
<summary>End-to-end span export & cross-service context propagation (incl. mixed env/config-file services) — 3 tests (1 env · 2 config)</summary>

- `ConfigBasicTest::testConfigFileConfiguresTracerProvider` — tbachert ✅ · official ❌
- `EnvEndToEndTest::testTraceContextPropagatesAcrossServices` — tbachert ✅ · official ✅
- `EnvEndToEndTest::testTraceContextPropagatesBetweenEnvAndConfigFileModes` — tbachert ✅ · official ❌

</details>

<details>
<summary>Sampling: always_on/off, trace-id ratio, parent-based (simple samplers) — 20 tests (11 env · 9 config)</summary>

- `ConfigSamplingTest::testAlwaysOffSampler` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testAlwaysOnSampler` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testParentBasedSampler` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testParentBasedSamplerHonorsPerParentOriginSamplers` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testParentBasedSamplerRootAlwaysOff` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testParentBasedSamplerRootRatioZeroStillSamplesRemoteChild` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testTraceIdRatioBasedSampler` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testTraceIdRatioBasedSamplerSamplesOnlyAFractionOfSpans` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testTraceIdRatioBasedSamplerZero` — tbachert ✅ · official ❌
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
<summary>Composite & rule-based samplers (`rule_based`, `probability`, `parent_threshold`, `always_record`) — 18 tests (0 env · 18 config)</summary>

- `ConfigProbabilitySamplerTest::testComposableProbabilitySampler` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerConsistentAcrossThresholds` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerUsesPropagatedExplicitRandomness` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testComposableProbabilitySamplerWritesThresholdTraceState` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testParentThresholdSamplerFollowsSampledRemoteParent` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testParentThresholdSamplerInheritsRemoteParentThreshold` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testProbabilitySamplerIgnoresInvalidExplicitRandomness` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testProbabilitySamplerPreservesPropagatedExplicitRandomness` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testProbabilitySamplerThresholdMatchesSamplingDecisions` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testProbabilitySamplerTraceStatePropagatesToChildSpans` — tbachert ✅ · official ❌
- `ConfigProbabilitySamplerTest::testProbabilitySamplerWritesThresholdTraceState` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testAlwaysRecordSamplerRecordsWithoutReporting` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testCompositeAlwaysOffDropsAllSpans` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testCompositeAlwaysOnSamplesAllSpans` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testRuleBasedSamplerExcludedAttributePatterns` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testRuleBasedSamplerMatchesAttributePatterns` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testRuleBasedSamplerMatchesParentOrigin` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testRuleBasedSamplerRoutesBySpanKindAndAttributes` — tbachert ✅ · official ❌

</details>

<details>
<summary>`jaeger_remote` sampler (remote dial, strategies, initial sampler) — 7 tests (5 env · 2 config)</summary>

- `ConfigSamplingTest::testJaegerRemoteInitialSamplerAppliesWhileBackendUnreachable` — tbachert ✅ · official ❌
- `ConfigSamplingTest::testJaegerRemoteSamplerDialsPlaintextEndpoint` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemoteInitialSamplerAppliesWhileBackendUnreachable` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemotePerOperationStrategyMatchesSpanNames` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemoteProbabilityStrategyIsApplied` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemoteRateLimitingStrategyLimitsSpans` — tbachert ✅ · official ❌
- `EnvSamplingTest::testJaegerRemoteSamplerDialsPlaintextEndpoint` — tbachert ✅ · official ❌

</details>

<details>
<summary>Span & attribute limits (count, value length, depth) — 15 tests (11 env · 4 config)</summary>

- `ConfigLimitsTest::testAttributeValueDepthLimitTruncatesNestedValues` — tbachert ✅ · official ❌
- `ConfigLimitsTest::testGeneralAttributeLimits` — tbachert ✅ · official ❌
- `ConfigLimitsTest::testSpanLimits` — tbachert ✅ · official ❌
- `ConfigLimitsTest::testTracerProviderAttributeValueDepthLimitTruncatesNestedValues` — tbachert ✅ · official ❌
- `EnvSpanLimitsTest::testAttributeCountLimit` — tbachert ✅ · official ❌
- `EnvSpanLimitsTest::testAttributeValueLengthLimit` — tbachert ✅ · official ❌
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

- `ConfigBatchSpanProcessorTest::testBatchSpanProcessorExportTimeoutIsAccepted` — tbachert ✅ · official ❌
- `ConfigBatchSpanProcessorTest::testBatchSpanProcessorMaxExportBatchSize` — tbachert ✅ · official ❌
- `ConfigBatchSpanProcessorTest::testBatchSpanProcessorScheduleDelay` — tbachert ✅ · official ❌
- `ConfigBatchSpanProcessorTest::testMultipleProcessorsExportEachSpanToAllConfiguredExporters` — tbachert ✅ · official ❌
- `ConfigBatchSpanProcessorTest::testSimpleSpanProcessorExportsOnSpanEnd` — tbachert ✅ · official ❌
- `EnvBatchSpanProcessorTest::testBspDropsSpansWhenQueueIsFull` — tbachert ✅ · official ❌
- `EnvBatchSpanProcessorTest::testBspExportTimeout` — tbachert ✅ · official ✅
- `EnvBatchSpanProcessorTest::testBspMaxExportBatchSize` — tbachert ✅ · official ✅
- `EnvBatchSpanProcessorTest::testBspMaxQueueSize` — tbachert ✅ · official ✅
- `EnvBatchSpanProcessorTest::testBspScheduleDelayDoesNotLoseSpansAfterFlush` — tbachert ✅ · official ✅

</details>

<details>
<summary>Span details: status, events, kinds, links, attribute value types — 6 tests (5 env · 1 config)</summary>

- `ConfigBasicTest::testSpansExportedWithStatusEventsKindsAndLinks` — tbachert ✅ · official ❌
- `EnvSpanDetailsTest::testSpanAttributesPreserveValueTypes` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanEventsAreExportedWithAttributes` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanKindsAreExported` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanLinksAreExportedWithAttributes` — tbachert ✅ · official ✅
- `EnvSpanDetailsTest::testSpanStatusIsExported` — tbachert ✅ · official ✅

</details>

## Metrics

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Metric pipeline: instruments, series separation, gauge/counter semantics | – | ✅ 8/8 | – | ❌ 0/8³ |
| Exemplar filter (always_on/off, trace_based, invalid fallback) | ✅ 4/4 | ✅ 2/2 | ⚠️ 1/4² | ❌ 0/2³ |
| Temporality preference (cumulative/delta) | ✅ 1/1 | ✅ 3/3 | 🟨 0/1⁷¹⁵ | ❌ 0/3³ |
| Default histogram aggregation (exponential buckets, spec boundaries) | ✅ 1/1 | ✅ 2/2 | ❌ 0/1⁸ | ❌ 0/2³ |
| Collection interval & periodic reader (batch size) | ✅ 1/1 | ✅ 3/3 | 🟨 0/1⁷¹⁵ | ❌ 0/3³ |
| Views: instrument & meter selection (incl. wildcards) | – | ✅ 9/9 | – | ❌ 0/9³ |
| Views: attribute key filtering (include/exclude, precedence) | – | ✅ 4/4 | – | ❌ 0/4³ |
| Views: aggregation overrides (histogram buckets, drop, sum/last-value) | – | ✅ 7/7 | – | ❌ 0/7³ |
| Views: metric renaming & description | – | ✅ 4/4 | – | ❌ 0/4³ |
| Views: matching behavior (no-match, multiple streams, ordering) | – | ✅ 5/5 | – | ❌ 0/5³ |
| Composable views (same-name merging, unnamed join, application order) | – | ✅ 6/6 | – | ❌ 0/6³ |
| Cardinality limits (overflow series) | – | ✅ 4/4 | – | ❌ 0/4³ |

<details>
<summary>Metric pipeline: instruments, series separation, gauge/counter semantics — 8 tests (0 env · 8 config)</summary>

- `ConfigMetricPipelineTest::testMetricsGaugeExportsLastValuePerCollection` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testMetricsPipelineExportsHistogramAggregationAcrossCollections` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testMetricsPipelineExportsMultipleInstrumentsAcrossMultipleCollectionCycles` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testMetricsPipelineKeepsMetricSeriesSeparateByAttributes` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testMultipleMetersProduceSeparateScopesInOneExport` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testObservableCounterExportsCumulativeObservedValue` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testObservableGaugeExportsObservedValue` — tbachert ✅ · official ❌
- `ConfigMetricPipelineTest::testUpDownCounterExportsNonMonotonicSum` — tbachert ✅ · official ❌

</details>

<details>
<summary>Exemplar filter (always_on/off, trace_based, invalid fallback) — 6 tests (4 env · 2 config)</summary>

- `ConfigMetricReaderTest::testMetricsExemplarFilterAlwaysOff` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testMetricsExemplarFilterAlwaysOn` — tbachert ✅ · official ❌
- `EnvMetricsTest::testInvalidExemplarFilterFallsBackToTraceBased` — tbachert ✅ · official ❌
- `EnvMetricsTest::testMetricsExemplarFilterAlwaysOff` — tbachert ✅ · official ✅
- `EnvMetricsTest::testMetricsExemplarFilterAlwaysOnCapturesWithoutSpan` — tbachert ✅ · official ❌
- `EnvMetricsTest::testMetricsExemplarFilterTraceBasedOnlyCapturesInSampledSpans` — tbachert ✅ · official ❌

</details>

<details>
<summary>Temporality preference (cumulative/delta) — 4 tests (1 env · 3 config)</summary>

- `ConfigMetricReaderTest::testMetricsCumulativeTemporalityPreservesStartTimestamp` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testMetricsExporterUsesCumulativeTemporality` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testMetricsExporterUsesDeltaTemporality` — tbachert ✅ · official ❌
- `EnvMetricsTest::testMetricsTemporalityPreferenceEnvVarUsesDelta` — tbachert ✅ · official ❌

</details>

<details>
<summary>Default histogram aggregation (exponential buckets, spec boundaries) — 3 tests (1 env · 2 config)</summary>

- `ConfigMetricPipelineTest::testDefaultHistogramUsesSpecBoundaries` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testExporterDefaultBase2ExponentialHistogramAggregation` — tbachert ✅ · official ❌
- `EnvMetricsTest::testDefaultHistogramAggregationEnvVarUsesExponentialBuckets` — tbachert ✅ · official ❌

</details>

<details>
<summary>Collection interval & periodic reader (batch size) — 4 tests (1 env · 3 config)</summary>

- `ConfigMetricReaderTest::testPeriodicMetricReader` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testPeriodicReaderMaxExportBatchSizeCountsDataPointsNotMetrics` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testPeriodicReaderMaxExportBatchSizeSplitsExports` — tbachert ✅ · official ❌
- `EnvMetricsTest::testMetricExportIntervalEnvVarControlsCollectionFrequency` — tbachert ✅ · official ❌

</details>

<details>
<summary>Views: instrument & meter selection (incl. wildcards) — 9 tests (0 env · 9 config)</summary>

- `ConfigViewsTest::testViewInstrumentNameSupportsWildcardPatterns` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSelectorMatchesAllSpecifiedInstrumentAndMeterCriteria` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSelectorRequiresAllSpecifiedCriteriaToMatch` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSelectsByInstrumentTypeAndUnit` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSelectsByMeterName` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSelectsByMeterNameVersionAndSchemaUrl` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSelectsByMeterSchemaUrl` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSelectsByMeterVersion` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewWithEmptySelectorMatchesEveryInstrument` — tbachert ✅ · official ❌

</details>

<details>
<summary>Views: attribute key filtering (include/exclude, precedence) — 4 tests (0 env · 4 config)</summary>

- `ConfigViewsTest::testMetricsViewCanFilterAttributes` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewAttributeKeysSupportIncludeAndExcludePatterns` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewExcludedAttributesTakePrecedenceOverIncludedAttributes` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewFiltersAttributeKeys` — tbachert ✅ · official ❌

</details>

<details>
<summary>Views: aggregation overrides (histogram buckets, drop, sum/last-value) — 7 tests (0 env · 7 config)</summary>

- `ConfigViewsTest::testViewAggregationPreservesInstrumentAttributes` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewCanDropAnInstrument` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewExplicitDefaultAggregationUsesInstrumentKind` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewIgnoresAggregationIncompatibleWithInstrumentKind` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewSupportsSumAndLastValueAggregations` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewUsesBase2ExponentialBucketHistogramAggregation` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewUsesExplicitBucketHistogramAggregation` — tbachert ✅ · official ❌

</details>

<details>
<summary>Views: metric renaming & description — 4 tests (0 env · 4 config)</summary>

- `ConfigViewsTest::testViewCanOverrideDescriptionWhilePreservingOriginalName` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewCanOverrideNameWhilePreservingOriginalDescription` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewPreservesOriginalNameAndDescriptionWhenOmitted` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewRenamesMetricAndChangesDescription` — tbachert ✅ · official ❌

</details>

<details>
<summary>Views: matching behavior (no-match, multiple streams, ordering) — 5 tests (0 env · 5 config)</summary>

- `ConfigViewsTest::testInstrumentWithoutMatchingViewRemainsUnchanged` — tbachert ✅ · official ❌
- `ConfigViewsTest::testMatchAllDropViewCanBeUsedAsDefaultWithSpecificView` — tbachert ✅ · official ❌
- `ConfigViewsTest::testMultipleMatchingViewsApplyTheirConfigurationsIndependently` — tbachert ✅ · official ❌
- `ConfigViewsTest::testMultipleMatchingViewsProduceMultipleStreams` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewIsAppliedBeforeMultipleMetricReadersExport` — tbachert ✅ · official ❌

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
<summary>Cardinality limits (overflow series) — 4 tests (0 env · 4 config)</summary>

- `ConfigMetricReaderTest::testReaderCardinalityLimitBucketsOverflowSeries` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testReaderCardinalityLimitsApplyPerInstrumentType` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewAggregationCardinalityLimitUsesOverflowSeries` — tbachert ✅ · official ❌
- `ConfigViewsTest::testViewAttributeFilteringOccursBeforeCardinalityLimit` — tbachert ✅ · official ❌

</details>

## Logs

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Log record content: severity, non-string & nested body types | ✅ 3/3 | ✅ 1/1 | ✅ 3/3 | ❌ 0/1³ |
| Log record limits (attribute count & value length) | ✅ 3/3 | ✅ 2/2 | ✅ 3/3 | ❌ 0/2³ |
| Batch & simple log record processors | ✅ 5/5 | ✅ 3/3 | ⚠️ 4/5⁶ | ❌ 0/3³ |
| Log severity threshold & trace-based filtering | – | ✅ 6/6 | – | ❌ 0/6³ |

<details>
<summary>Log record content: severity, non-string & nested body types — 4 tests (3 env · 1 config)</summary>

- `ConfigLogRecordTest::testLogRecordsExportedWithSeverityAndTypedBodies` — tbachert ✅ · official ❌
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

- `ConfigLogRecordTest::testBatchLogRecordProcessorMaxExportBatchSize` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testBatchLogRecordProcessorScheduleDelay` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testSimpleLogRecordProcessorExportsOnEmit` — tbachert ✅ · official ❌
- `EnvLogRecordTest::testBlrpDropsLogRecordsWhenQueueIsFull` — tbachert ✅ · official ❌
- `EnvLogRecordTest::testBlrpExportTimeout` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testBlrpMaxExportBatchSize` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testBlrpMaxQueueSize` — tbachert ✅ · official ✅
- `EnvLogRecordTest::testBlrpScheduleDelayDoesNotLoseLogsAfterFlush` — tbachert ✅ · official ✅

</details>

<details>
<summary>Log severity threshold & trace-based filtering — 6 tests (0 env · 6 config)</summary>

- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorDefaultConfigAppliesMinimumSeverityAndTraceBased` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorMinimumSeverity` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorMinimumSeverityFiltersBelowThreshold` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorTraceBasedDropsLogsFromUnsampledTraces` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorTraceBasedFalseDoesNotFilterUnsampledTraces` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorTraceBasedFiltersUnsampledTraceRecords` — tbachert ✅ · official ❌

</details>

## Resource & entities

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Resource attributes & service.name | ✅ 8/8 | ✅ 5/5 | ✅ 8/8 | ❌ 0/5³ |
| Entities (`OTEL_ENTITIES`, env detector) | ✅ 7/7 | ✅ 1/1 | ❌ 0/7⁹ | ❌ 0/1³ |
| Resource detectors & attribute include/exclude (config file) | – | ✅ 10/10 | – | ❌ 0/10³ |

<details>
<summary>Resource attributes & service.name — 13 tests (8 env · 5 config)</summary>

- `ConfigResourceTest::testExplicitResourceAttributesOverrideAttributesList` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceAttributes` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceAttributesList` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceAttributesSupportNonStringValues` — tbachert ✅ · official ❌
- `ConfigResourceTest::testServiceNameFallsBackToSpecDefaultWhenUnset` — tbachert ✅ · official ❌
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
<summary>Resource detectors & attribute include/exclude (config file) — 10 tests (0 env · 10 config)</summary>

- `ConfigResourceTest::testContainerDetectorReportsContainerId` — tbachert ✅ · official ❌
- `ConfigResourceTest::testHostDetectorPopulatesHostAndOsAttributes` — tbachert ✅ · official ❌
- `ConfigResourceTest::testMultipleResourceDetectorsAreCombined` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceDetectionIsDisabledWithoutDetectionNode` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceDetectorExcludesSelectedAttributes` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceDetectorExclusionTakesPrecedenceOverInclusion` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceDetectorIncludesOnlySelectedAttributes` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceSchemaUrlConflictingWithDetectedResourcesIsDropped` — tbachert ✅ · official ❌
- `ConfigResourceTest::testResourceSchemaUrlMatchingDetectedResourcesIsExported` — tbachert ✅ · official ❌
- `ConfigResourceTest::testServiceDetectorReadsServiceNameFromEnvironmentVariable` — tbachert ✅ · official ❌

</details>

## Exporters & transport

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| OTLP HTTP exporter options: protocol, headers, compression, endpoints, timeouts, size limits, retries | ✅ 24/24 | ✅ 18/18 | ⚠️ 21/24¹¹⁰ | ❌ 0/18³ |
| OTLP gRPC exporter (all signals) | ✅ 12/12 | ✅ 4/4 | ❌ 0/12¹¹ | ❌ 0/4³ |
| OTLP file exporter (newline-delimited JSON on disk) | – | ✅ 1/1 | – | ❌ 0/1³ |
| TLS: CA trust & client certificates (all signals, both protocols) | ✅ 11/11 | ✅ 6/6 | ❌ 0/11¹² | ❌ 0/6³ |
| Console exporter (stdout) | ✅ 3/3 | ✅ 3/3 | ✅ 3/3 | ❌ 0/3³ |
| Prometheus exporter (pull, translation & escaping) | ✅ 1/1 | ✅ 9/9 | ❌ 0/1¹³ | ❌ 0/9³ |

<details>
<summary>OTLP HTTP exporter options: protocol, headers, compression, endpoints, timeouts, size limits, retries — 42 tests (24 env · 18 config)</summary>

- `ConfigBasicTest::testOtlpHttpEncodingJsonIsApplied` — tbachert ✅ · official ❌
- `ConfigBasicTest::testOtlpHttpExporterHeadersAreSentToCollector` — tbachert ✅ · official ❌
- `ConfigBasicTest::testOtlpHttpGzipCompressionIsApplied` — tbachert ✅ · official ❌
- `ConfigBasicTest::testOtlpHttpMaxRequestSizeBlocksOversizedExports` — tbachert ✅ · official ❌
- `ConfigBasicTest::testOtlpHttpMaxResponseSizeRejectsLargeResponses` — tbachert ✅ · official ❌
- `ConfigBasicTest::testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testOtlpHttpEncodingJsonIsApplied` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testOtlpHttpExporterHeadersAreSentToCollector` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testOtlpHttpGzipCompressionIsApplied` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testOtlpHttpMaxRequestSizeBlocksOversizedExports` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testOtlpHttpMaxResponseSizeRejectsLargeResponses` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpHttpEncodingJsonIsApplied` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpHttpExporterHeadersAreSentToCollector` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpHttpGzipCompressionIsApplied` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpHttpMaxRequestSizeBlocksOversizedExports` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpHttpMaxResponseSizeRejectsLargeResponses` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow` — tbachert ✅ · official ❌
- `OtlpExporterTest::testCollectorRejectingExportDoesNotBreakShutdown` — tbachert ✅ · official ❌
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

- `GrpcTest::testConfigFileGrpcExporterExportsLogs` — tbachert ✅ · official ❌
- `GrpcTest::testConfigFileGrpcExporterExportsMetrics` — tbachert ✅ · official ❌
- `GrpcTest::testConfigFileGrpcExporterExportsSpans` — tbachert ✅ · official ❌
- `GrpcTest::testConfigFileInsecureGrpcExporter` — tbachert ✅ · official ❌
- `GrpcTest::testEnvGrpcGzipCompressionIsApplied` — tbachert ✅ · official ❌
- `GrpcTest::testEnvGrpcInsecureEnvVarDialsPlaintext` — tbachert ✅ · official ❌
- `GrpcTest::testEnvGrpcProtocolExportsSpans` — tbachert ✅ · official ❌
- `GrpcTest::testEnvGrpcProtocolSendsConfiguredHeadersAsMetadata` — tbachert ✅ · official ❌
- `GrpcTest::testEnvGrpcSchemelessEndpointIsSecureByDefault` — tbachert ✅ · official ❌
- `GrpcTest::testEnvGrpcWithoutTls` — tbachert ✅ · official ❌
- `GrpcTest::testGenericGrpcInsecureEnvVarDialsPlaintext` — tbachert ✅ · official ❌
- `GrpcTest::testGenericGrpcProtocolAppliesToAllSignals` — tbachert ✅ · official ❌
- `GrpcTest::testGrpcErrorStatusIsReportedWithoutRetry` — tbachert ✅ · official ❌
- `GrpcTest::testLogsInsecureEnvVarDialsPlaintext` — tbachert ✅ · official ❌
- `GrpcTest::testMetricsInsecureEnvVarDialsPlaintext` — tbachert ✅ · official ❌
- `GrpcTest::testPerSignalGrpcInsecureOverridesGeneric` — tbachert ✅ · official ❌

</details>

<details>
<summary>OTLP file exporter (newline-delimited JSON on disk) — 1 tests (0 env · 1 config)</summary>

- `ConfigBasicTest::testOtlpFileExporterWritesNewlineDelimitedJson` — tbachert ✅ · official ❌

</details>

<details>
<summary>TLS: CA trust & client certificates (all signals, both protocols) — 17 tests (11 env · 6 config)</summary>

- `GrpcTest::testGrpcClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ❌
- `GrpcTest::testGrpcLogsClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ❌
- `GrpcTest::testGrpcMetricsClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ❌
- `TlsTest::testConfigFileCaFileTrustsSelfSignedCollector` — tbachert ✅ · official ❌
- `TlsTest::testConfigFileCaFileTrustsSelfSignedLogsCollector` — tbachert ✅ · official ❌
- `TlsTest::testConfigFileCaFileTrustsSelfSignedMetricsCollector` — tbachert ✅ · official ❌
- `TlsTest::testConfigFileClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ❌
- `TlsTest::testEnvCertificateTrustsSelfSignedCollector` — tbachert ✅ · official ❌
- `TlsTest::testEnvClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ❌
- `TlsTest::testGenericCertificateTrustsSelfSignedCollector` — tbachert ✅ · official ❌
- `TlsTest::testGenericClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ❌
- `TlsTest::testLogsCertificateEnvVarTrustsSelfSignedCollector` — tbachert ✅ · official ❌
- `TlsTest::testLogsClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ❌
- `TlsTest::testMetricsCertificateEnvVarTrustsSelfSignedCollector` — tbachert ✅ · official ❌
- `TlsTest::testMetricsClientCertificateIsPresentedAndVerified` — tbachert ✅ · official ❌
- `TlsTest::testMissingClientCertificateIsRejected` — tbachert ✅ · official ❌
- `TlsTest::testUnknownCaIsRejectedByDefaultVerification` — tbachert ✅ · official ❌

</details>

<details>
<summary>Console exporter (stdout) — 6 tests (3 env · 3 config)</summary>

- `ConfigBasicTest::testConsoleExporterWritesSpansToStdout` — tbachert ✅ · official ❌
- `ConfigLogRecordTest::testConsoleExporterWritesLogsToStdout` — tbachert ✅ · official ❌
- `ConfigMetricReaderTest::testConsoleExporterWritesMetricsToStdout` — tbachert ✅ · official ❌
- `EnvLogRecordTest::testLogsConsoleExporterWritesToStdout` — tbachert ✅ · official ✅
- `EnvMetricsTest::testMetricsConsoleExporterWritesToStdout` — tbachert ✅ · official ✅
- `EnvSdkTest::testConsoleExporterWritesSpansToStdout` — tbachert ✅ · official ✅

</details>

<details>
<summary>Prometheus exporter (pull, translation & escaping) — 10 tests (1 env · 9 config)</summary>

- `ConfigPrometheusExporterTest::testPrometheusEscapingAllowUtf8PreservesValidUtf8Names` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusEscapingDotsSchemeEscapesDotsAndUnderscores` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusEscapingUnderscoresReplacesNonLegacyCharacters` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusEscapingValuesSchemeEncodesCodePoints` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusReaderInfoMetricsCanBeDisabled` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusReaderServesMetricsOnConfiguredPort` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusResourceConstantLabelsAreExcluded` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusResourceConstantLabelsAreIncluded` — tbachert ✅ · official ❌
- `ConfigPrometheusExporterTest::testPrometheusTranslationStrategyWithoutSuffixes` — tbachert ✅ · official ❌
- `EnvMetricsTest::testPrometheusExporterServesMetricsOnConfiguredPort` — tbachert ✅ · official ❌

</details>

## SDK-level & cross-cutting

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| SDK enablement & per-signal exporter selection (`OTEL_SDK_DISABLED`, `*_EXPORTER`) | ✅ 10/10 | ✅ 1/1 | ⚠️ 9/10¹ | ❌ 0/1³ |
| Provider configurators: scope filtering (wildcards, case sensitivity, isolation) | – | ✅ 18/18 | – | ❌ 0/18³ |
| Env value parsing & leniency: empty values, invalid sampler/propagator, malformed attributes (env-only semantics) | ✅ 5/5 | – | ⚠️ 2/5¹ | – |
| `OTEL_LOG_LEVEL` (self-diagnostic output) | ✅ 1/1 | ✅ 1/1 | ✅ 1/1 | ❌ 0/1³ |
| Config file basics: no-op SDK, format versioning, missing file, independent signals | – | ✅ 4/4 | – | ⚠️ 1/4³¹⁴ |
| ID generation (random) | – | ✅ 1/1 | – | ❌ 0/1³ |
| Variable substitution: `${}`, defaults, escaping, type coercion | – | ✅ 9/9 | – | ⚠️ 1/9³ |
| Environment variable precedence & ignore rules in config-file mode | – | ✅ 5/5 | – | ❌ 0/5³ |
| SDK self-observability disabled by default | – | ✅ 1/1 | – | 🟨 0/1³¹⁵ |

<details>
<summary>SDK enablement & per-signal exporter selection (`OTEL_SDK_DISABLED`, `*_EXPORTER`) — 11 tests (10 env · 1 config)</summary>

- `ConfigBasicTest::testDisabledConfigurationDisablesAllSignals` — tbachert ✅ · official ❌
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

- `ConfigConfiguratorsTest::testConfiguratorConfigurationIsIsolatedBetweenSignals` — tbachert ✅ · official ❌
- `ConfigConfiguratorsTest::testConfiguratorDefaultsAreEnabledWhenDefaultConfigIsOmitted` — tbachert ✅ · official ❌
- `ConfigConfiguratorsTest::testConfiguratorsSupportAsteriskWildcard` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorCanDisableDefaultLoggersAndEnableMatchingLogger` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorCanDisableMatchingLoggerWithDefaultEnabled` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorMatchingIsCaseSensitive` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorQuestionMarkWildcard` — tbachert ✅ · official ❌
- `ConfigLoggerConfiguratorTest::testLoggerConfiguratorSupportsWildcardMatching` — tbachert ✅ · official ❌
- `ConfigMeterConfiguratorTest::testMeterConfiguratorCanDisableDefaultMetersAndEnableMatchingMeter` — tbachert ✅ · official ❌
- `ConfigMeterConfiguratorTest::testMeterConfiguratorCanDisableMatchingMeterWithDefaultEnabled` — tbachert ✅ · official ❌
- `ConfigMeterConfiguratorTest::testMeterConfiguratorMatchingIsCaseSensitive` — tbachert ✅ · official ❌
- `ConfigMeterConfiguratorTest::testMeterConfiguratorQuestionMarkWildcard` — tbachert ✅ · official ❌
- `ConfigMeterConfiguratorTest::testMeterConfiguratorSupportsWildcardMatching` — tbachert ✅ · official ❌
- `ConfigTracerConfiguratorTest::testTracerConfiguratorCanDisableDefaultTracersAndEnableMatchingTracer` — tbachert ✅ · official ❌
- `ConfigTracerConfiguratorTest::testTracerConfiguratorCanDisableMatchingTracer` — tbachert ✅ · official ❌
- `ConfigTracerConfiguratorTest::testTracerConfiguratorMatchingIsCaseSensitive` — tbachert ✅ · official ❌
- `ConfigTracerConfiguratorTest::testTracerConfiguratorQuestionMarkWildcard` — tbachert ✅ · official ❌
- `ConfigTracerConfiguratorTest::testTracerConfiguratorSupportsWildcardMatching` — tbachert ✅ · official ❌

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
<summary>`OTEL_LOG_LEVEL` (self-diagnostic output) — 2 tests (1 env · 1 config)</summary>

- `ConfigBasicTest::testLogLevelErrorSuppressesSdkWarnings` — tbachert ✅ · official ❌
- `EnvSdkTest::testLogLevelNoneSuppressesSelfDiagnostics` — tbachert ✅ · official ✅

</details>

<details>
<summary>Config file basics: no-op SDK, format versioning, missing file, independent signals — 4 tests (0 env · 4 config)</summary>

- `ConfigBasicTest::testConfigFileNewerMinorFormatIsAcceptedWithWarning` — tbachert ✅ · official ❌
- `ConfigBasicTest::testMissingConfigFileFailsInitialization` — tbachert ✅ · official ❌
- `ConfigBasicTest::testSignalsCanBeConfiguredIndependently` — tbachert ✅ · official ❌
- `ConfigBasicTest::testUnsupportedFileFormatResultsInNoOpSdk` — tbachert ✅ · official ✅

</details>

<details>
<summary>ID generation (random) — 1 tests (0 env · 1 config)</summary>

- `ConfigBasicTest::testIdGeneratorRandomProducesSpecConformIds` — tbachert ✅ · official ❌

</details>

<details>
<summary>Variable substitution: `${}`, defaults, escaping, type coercion — 9 tests (0 env · 9 config)</summary>

- `ConfigSubstitutionTest::testDefaultValueIsUsedOnlyWhenVariableIsUnset` — tbachert ✅ · official ❌
- `ConfigSubstitutionTest::testDoubleDollarEscapesSubstitution` — tbachert ✅ · official ❌
- `ConfigSubstitutionTest::testEnvironmentValueCannotInjectYamlStructure` — tbachert ✅ · official ❌
- `ConfigSubstitutionTest::testInvalidSubstitutionFailsInitialization` — tbachert ✅ · official ✅
- `ConfigSubstitutionTest::testMultipleReferencesInOneString` — tbachert ✅ · official ❌
- `ConfigSubstitutionTest::testPlainAndEnvPrefixedReferencesResolveToTheSameValue` — tbachert ✅ · official ❌
- `ConfigSubstitutionTest::testSubstitutedBooleanIsCoercedForTypedNode` — tbachert ✅ · official ❌
- `ConfigSubstitutionTest::testSubstitutedHexIntegerIsCoercedForTypedNode` — tbachert ✅ · official ❌
- `ConfigSubstitutionTest::testSubstitutionIsNotRecursive` — tbachert ✅ · official ❌

</details>

<details>
<summary>Environment variable precedence & ignore rules in config-file mode — 5 tests (0 env · 5 config)</summary>

- `ConfigBasicTest::testConfigFileModeIgnoresOtherEnvironmentVariables` — tbachert ✅ · official ❌
- `ConfigPrecedenceTest::testConfigFileIgnoresSdkDisabledEnvVar` — tbachert ✅ · official ❌
- `ConfigPrecedenceTest::testConfigFileIgnoresTracesSamplerEnvVar` — tbachert ✅ · official ❌
- `ConfigPrecedenceTest::testConfigFileResourceAttributesTakePrecedenceOverEnvironment` — tbachert ✅ · official ❌
- `ConfigPrecedenceTest::testConfigModeDefaultPropagatorIsNone` — tbachert ✅ · official ❌

</details>

<details>
<summary>SDK self-observability disabled by default — 1 tests (0 env · 1 config)</summary>

- `ConfigSelfObservabilityTest::testSelfObservabilityIsDisabledByDefault` — tbachert ✅ · official ❌

</details>

## Footnotes

¹ Lenient handling of invalid configuration: unrecognized or differently-cased
enum values (exporter, propagator, sampler, protocol names) and unparseable
values (numeric limits, `OTEL_TRACES_SAMPLER_ARG`, malformed
`OTEL_RESOURCE_ATTRIBUTES`) abort SDK initialization — or silently drop the
propagator — instead of logging a warning and falling back to the default.  
² All exemplar tests fail: none are captured/exported with the spec's filter
values (`trace_based`, `always_on`), and an invalid value falls back to no
exemplars instead of the default `trace_based`
(issue [#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054),
fix in [PR #2057](https://github.com/open-telemetry/opentelemetry-php/pull/2057) — open).  
³ Blocked by the `file_format` gate: the official `sdk-configuration` package
only accepts `file_format: '1.0-rc.2'`, while the suite uses data model version
1.2, so every config-file test fails at initialization. Expected to be resolved
by [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050) (draft).  
⁴ The `jaeger_remote` sampler is not implemented:
`OTEL_TRACES_SAMPLER=jaeger_remote` fails initialization with "unknown sampler"
(untracked upstream).  
⁵ The 2 failures are the **global** attribute limit vars
(`OTEL_ATTRIBUTE_COUNT_LIMIT`, `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT`) — declared
but not applied (issue
[#2055](https://github.com/open-telemetry/opentelemetry-php/issues/2055), fix in
[PR #2058](https://github.com/open-telemetry/opentelemetry-php/pull/2058) — open);
the third is an unparseable limit aborting initialization (footnote ¹). The
signal-specific `OTEL_SPAN_*` limits work.  
⁶ Queue-full drop fails: the batch processor flushes synchronously on span
end, so the queue never fills (spec deviation, untracked upstream).  
⁷ The periodic-exporting metric reader does not work
([#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884)): the
export interval env var is ignored, and delta temporality is unobservable in a
single shutdown collection.  
⁸ `OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION` is declared but
never applied (untracked upstream).  
⁹ The env entity detector is not implemented (untracked upstream).  
¹⁰ The exporter retries non-retryable `500` responses (four attempts observed);
the specification requires that all `4xx`/`5xx` codes other than 429, 502, 503
and 504 MUST NOT be retried (fixed in
[PR #2061](https://github.com/open-telemetry/opentelemetry-php/pull/2061), merged,
not yet released).  
¹¹ The 12 env-based tests fail because per-signal gRPC endpoints are used as-is
(the method path is only appended to the *generic* endpoint), which aborts
initialization. The HTTP/2 exchange itself has been verified to work (a raw
C-core client delivers correct gRPC frames to the suite's capture server), so
no interop problem exists.  
¹² The certificate environment variables
(`OTEL_EXPORTER_OTLP[_<signal>]*_CERTIFICATE`, `_CLIENT_CERTIFICATE`,
`_CLIENT_KEY`) are declared but never passed to the transports, for all
signals and both protocols. The gRPC wiring is fixed in
[PR #2059](https://github.com/open-telemetry/opentelemetry-php/pull/2059)
(merged, not yet released); the OTLP/HTTP side is addressed by
[PR #2060](https://github.com/open-telemetry/opentelemetry-php/pull/2060)
(merged, not yet released), which forwards the certificates from the PSR
transport factory to the Guzzle/Symfony transports and builds on PR #2059's
exporter-side forwarding.  
¹³ No exporter factory is registered for the protocol; structurally requires an
async runtime to serve the pull reader; excluded from upstream issue reporting
by decision.  
¹⁴ A missing configuration file causes an uncaught fatal error instead of a
reported initialization error (untracked upstream). The one passing test pins
the current no-op behavior for unsupported file formats.  
¹⁵ Cell marked 🟨: every failing test in that cell belongs to the
`async` group, so the cell passes once those tests are excluded. These cells are
counted in the run totals and the tbachert columns, which include the `async`
group.  

## Upstream tracking (official SDK failures, by root cause)

| Failing group | Tests | Tracking |
|---|---:|---|
| File-based configuration (`file_format` 1.2 gate): all config-file tests except the two passing pins, the `jaeger_remote` config tests and the missing-file test | 210 | fix in [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050) (draft) |
| gRPC exporter env-based configuration: per-signal endpoints abort initialization; certificate & insecure env vars not wired (env mode) | 15 | fix in [PR #2059](https://github.com/open-telemetry/opentelemetry-php/pull/2059) (merged, not yet released) |
| Certificate env vars never wired into the transports (OTLP/HTTP, all signals) | 8 | fix in [PR #2060](https://github.com/open-telemetry/opentelemetry-php/pull/2060) (merged, not yet released), builds on [PR #2059](https://github.com/open-telemetry/opentelemetry-php/pull/2059) (merged, not yet released) |
| Lenient handling of invalid configuration: unrecognized/case-mismatched enums and unparseable values abort init instead of warning + fallback | 10 | untracked |
| Entities (`OTEL_ENTITIES`) not implemented | 7 | untracked |
| `jaeger_remote` sampler not implemented (5 env + 2 config-file) | 7 | untracked |
| Exemplars / exemplar filter: spec values unrecognized, invalid → no exemplars; none captured or exported | 5 | [#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054), fix in [PR #2057](https://github.com/open-telemetry/opentelemetry-php/pull/2057) (open) |
| Global attribute limits declared but not applied | 2 | [#2055](https://github.com/open-telemetry/opentelemetry-php/issues/2055), fix in [PR #2058](https://github.com/open-telemetry/opentelemetry-php/pull/2058) (open) |
| Batch processor queue-full drop: blocking I/O on span end / log emit | 2 | untracked |
| Metric export interval / periodic reader (+ temporality dependency) | 2 | [#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884) |
| Prometheus pull exporter (needs async runtime) | 1 | not reported upstream (by decision) |
| Default histogram aggregation env var declared but not applied | 1 | untracked |
| Non-retryable HTTP `500` responses are retried | 1 | fix in [PR #2061](https://github.com/open-telemetry/opentelemetry-php/pull/2061) (merged, not yet released) |
| Missing config file: uncaught fatal error instead of a reported initialization error | 1 | untracked |
| **Total** | **272** | |

Caveats:

- The two passing official config-file tests:
  `testUnsupportedFileFormatResultsInNoOpSdk` pins the current no-op behavior
  for unsupported file formats, and `testInvalidSubstitutionFailsInitialization`
  passes for the wrong reason (initialization already fails on the unsupported
  file format).
- The config-file, gRPC-config and TLS-config failures are all *gated*: they
  cannot exercise the underlying feature until `file_format` 1.2 is accepted,
  so their true pass rate on the official SDK is unknown until PR #2050 lands.
- The env-var-based gRPC/TLS failures do not depend on that gate and pin the
  wiring gaps directly.
- "Untracked" items are genuine spec-scope gaps identified by this suite.

## Deliberate deviation pins

- `OTEL_RESOURCE_ATTRIBUTES` percent-decodes values but not keys in **both**
  SDKs, although a strict reading of the spec implies decoding both. Cross-SDK
  check (2026-09-17): Go, Java, Python and .NET also decode values only — only
  the JS SDK decodes keys. The suite therefore pins the de facto cross-language
  behavior (`EnvResourceTest::testResourceAttributesDecodeValuesButNotKeys`);
  a single-SDK "fix" would break parity with the other six implementations.

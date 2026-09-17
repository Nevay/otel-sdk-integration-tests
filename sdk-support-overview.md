# SDK support overview

Feature-by-feature pass/fail status of the shared test suite against the two
SDKs under `sdks/`. Generated from full suite runs (JUnit logs) after commit
`0667c12`; re-run with `make test SDK=<tbachert|official>` to refresh.

- **tbachert run:** 372 tests executed, **372 passing** (the 4
  `official`-tagged tests are excluded).
- **official run:** 373 tests executed, **106 passing / 267 failing** (the 3
  `tbachert`-tagged tests are excluded). Most failures (211 of 267) are gated
  by not-yet-updated config-file support: the SDK only accepts
  `file_format: '1.0-rc.2'`, while the suite uses data model version 1.2.
  Every failure pins behavior that is in scope of the official specification.

Legend: ✅ all passing · ❌ partially or fully failing · ➖ not applicable to
that SDK. Each test is counted in exactly one row; the "Upstream tracking"
table at the bottom accounts for all 267 failures by root cause.

## Traces

| Feature (test class) | tbachert/otel-sdk | open-telemetry/sdk |
|---|---|---|
| Span creation & OTLP/HTTP export, env options incl. per-signal headers/compression/timeout, User-Agent, protocol case-insensitivity & fallback (`EnvEndToEndTest`, `OtlpExporterTest`) | ✅ 28/28 | ❌ 23/28¹²³ |
| Propagators: tracecontext, baggage, b3, b3multi, env edge cases (`EnvPropagatorTest`) | ✅ 17/17 | ❌ 16/17² |
| Samplers: all types incl. parent-based variants and `jaeger_remote` (`EnvSamplingTest`) | ✅ 16/16 | ❌ 9/16²⁴ |
| Span limits, `OTEL_SPAN_*` + global limit vars (`EnvSpanLimitsTest`) | ✅ 11/11 | ❌ 8/11²⁵ |
| Batch span processor env options (`EnvBatchSpanProcessorTest`) | ✅ 5/5 | ❌ 4/5⁶ |
| Span details: events, links, status (`EnvSpanDetailsTest`) | ✅ 5/5 | ✅ 5/5 |
| Cross-signal correlation & exemplars (`EnvCorrelationTest`) | ✅ 4/4 | ❌ 2/4⁷ |
| Invalid-configuration leniency + OTLP failure handling (`EnvEdgeCasesTest`) | ✅ 5/5 | ❌ 2/5² |

¹ The failing test exercises **config-file mode** (cross-mode trace context
propagation) and is blocked by the `file_format` gate, see below.  
² Lenient handling of invalid configuration: unrecognized or differently-cased
enum values (exporter, propagator, sampler, protocol names) and unparseable
values (numeric limits, `OTEL_TRACES_SAMPLER_ARG`, malformed
`OTEL_RESOURCE_ATTRIBUTES`) abort SDK initialization — or silently drop the
propagator — instead of logging a warning and falling back to the default.  
³ The exporter retries non-retryable `500` responses (four attempts observed);
the specification requires that all `4xx`/`5xx` codes other than 429, 502, 503
and 504 MUST NOT be retried (untracked upstream).  
⁴ The `jaeger_remote` sampler is not implemented: `OTEL_TRACES_SAMPLER=jaeger_remote`
fails initialization with "unknown sampler" (5 tests; untracked upstream).  
⁵ The 2 failures are the **global** attribute limit vars
(`OTEL_ATTRIBUTE_COUNT_LIMIT`, `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT`) — declared
but not applied ([#2055](https://github.com/open-telemetry/opentelemetry-php/issues/2055));
the third is an unparseable limit aborting initialization (footnote ²). The
signal-specific `OTEL_SPAN_*` limits work.  
⁶ Queue-full drop fails: the batch processor flushes synchronously on span
end, so the queue never fills (spec deviation, untracked upstream).  
⁷ All exemplar tests fail: none are captured/exported with the spec's filter
values (`trace_based`, `always_on`), and an invalid value falls back to no
exemplars instead of the default `trace_based`
([#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054)).  

## Logs

| Feature (test class) | tbachert/otel-sdk | open-telemetry/sdk |
|---|---|---|
| Log record env options: BLRP, limits, severity (`EnvLogRecordTest`) | ✅ 12/12 | ❌ 11/12⁶ |

Same queue-full drop deviation as the batch span processor.

## Metrics

| Feature (test class) | tbachert/otel-sdk | open-telemetry/sdk |
|---|---|---|
| Export interval & temporality preference env vars (`EnvMetricsTest`) | ✅ 2/2 | ❌ 0/2⁸ |
| Default histogram aggregation env var (`EnvMetricsTest`) | ✅ 1/1 | ❌ 0/1⁹ |
| Exemplar filter env vars incl. invalid-value fallback (`EnvMetricsTest`) | ✅ 4/4 | ❌ 1/4⁷ |
| Prometheus pull exporter, env mode (`EnvMetricsTest`) | ✅ 1/1 | ❌ 0/1¹⁰ |
| Console exporter & basic collection (`EnvMetricsTest`) | ✅ 1/1 | ✅ 1/1 |

⁸ The periodic-exporting metric reader does not work
([#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884));
the temporality test additionally depends on it (delta is unobservable in a
single shutdown collection).  
⁹ `OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION` is declared but
never applied (untracked upstream).  
¹⁰ No exporter factory is registered for the protocol; structurally requires
an async runtime to serve the pull reader; excluded from upstream issue
reporting by decision.  

## Exporters & transport

| Feature (test class) | tbachert/otel-sdk | open-telemetry/sdk |
|---|---|---|
| gRPC exporter: env + config file, TLS/insecure incl. `OTEL_EXPORTER_OTLP[_<signal>]*_INSECURE` (`GrpcTest`) | ✅ 16/16 | ❌ 0/16¹¹¹² |
| TLS: CA file, client certificate — env mode, all signals (`TlsTest`) | ✅ 8/8 | ❌ 0/8¹³ |
| TLS via config file: CA file, mTLS, default verification, missing client cert (`TlsTest`) | ✅ 6/6 | ❌ 0/6¹² |

¹¹ The 12 env-based tests fail because per-signal gRPC endpoints are used as-is
(the method path is only appended to the *generic* endpoint), which aborts
initialization. The HTTP/2 exchange itself has been verified to work (a raw
C-core client delivers correct gRPC frames to the suite's capture server), so
no interop problem exists.  
¹² Blocked by the `file_format` gate, see below.  
¹³ The certificate environment variables (`OTEL_EXPORTER_OTLP[_<signal>]*_CERTIFICATE`,
`_CLIENT_CERTIFICATE`, `_CLIENT_KEY`) are declared but never passed to the
transport factories, for all signals and both protocols.  

## Resource & entities

| Feature (test class) | tbachert/otel-sdk | open-telemetry/sdk |
|---|---|---|
| Resource attributes & service name, env vars incl. decoding behavior (`EnvResourceTest`) | ✅ 8/8 | ✅ 8/8 |
| Entities via `OTEL_ENTITIES`, env mode (`EnvResourceTest`) | ✅ 7/7 | ❌ 0/7¹⁴ |
| Resource detectors & attribute include/exclude (config file: `ConfigResourceTest`) | ✅ 16/16 | ❌ 0/16¹² |

¹⁴ The env entity detector is not implemented (untracked upstream).  

## SDK-level & per-SDK surface

| Feature (test class) | tbachert/otel-sdk | open-telemetry/sdk |
|---|---|---|
| SDK diagnostics: log level, shutdown behavior (`EnvSdkTest`) | ✅ 11/11 | ✅ 10/10¹⁵ |
| Official-SDK-specific env vars, `OTEL_PHP_*` (`OfficialSpecificTest`) | ➖ excluded | ✅ 4/4 |

¹⁵ One test is tagged `tbachert` (it asserts the vendor's log message
wording) and is excluded from this run.  

## File-based configuration (data model v1.2)

| Feature (test classes) | tbachert/otel-sdk | open-telemetry/sdk |
|---|---|---|
| All config-file features: pipeline & exporter options, samplers (incl. consistent probability sampling tracestate), views & composable views (incl. wildcard instrument names), configurators, limits, propagators, processors, resource detection, precedence, substitution, metric readers (`Config*Test`) | ✅ 204/204 | ❌ 2/202¹⁶ |

¹⁶ The official `sdk-configuration` package only accepts
`file_format: '1.0-rc.2'`; the suite uses data model version 1.2, so every
config-file test fails at initialization. Expected to be resolved by
[PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050). The
two passing tests: `testUnsupportedFileFormatResultsInNoOpSdk` pins the
current no-op behavior for unsupported file formats, and
`testInvalidSubstitutionFailsInitialization` passes for the wrong reason
(initialization already fails on the unsupported file format). Two `Config*`
tests are tagged `tbachert` and not executed in this run (the vendor
`capture_code_attributes` processor, self-observability enablement).  

## Upstream tracking (official SDK failures, by root cause)

| Failing group | Tests | Tracking |
|---|---:|---|
| File-based configuration (`file_format` 1.2 gate): all `Config*` tests except the two passing pins, config-file gRPC & TLS tests, cross-mode E2E test | 211 | [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050) |
| gRPC: per-signal endpoints abort initialization (env mode) | 12 | untracked |
| Certificate env vars never wired into the transports (OTLP/HTTP, all signals) | 8 | untracked |
| Entities (`OTEL_ENTITIES`) not implemented | 7 | untracked |
| Lenient handling of invalid configuration: unrecognized/case-mismatched enums and unparseable values abort init instead of warning + fallback | 10 | untracked |
| Exemplars / exemplar filter: spec values unrecognized, invalid → no exemplars; none captured or exported | 5 | [#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054) |
| `jaeger_remote` sampler not implemented (env mode) | 5 (+2 in file gate) | untracked |
| Global attribute limits declared but not applied | 2 | [#2055](https://github.com/open-telemetry/opentelemetry-php/issues/2055) |
| Batch processor queue-full drop: blocking I/O on span end / log emit | 2 | untracked |
| Metric export interval / periodic reader (+ temporality dependency) | 2 | [#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884) |
| Prometheus pull exporter (needs async runtime) | 1 (+9 in file gate) | not reported upstream (by decision) |
| Default histogram aggregation env var declared but not applied | 1 | untracked |
| Non-retryable HTTP `500` responses are retried | 1 | untracked |
| Missing config file: uncaught fatal error instead of a reported initialization error | 1 | untracked |
| **Total** | **267** | |

Caveats:

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

# SDK support overview

Feature-by-feature pass/fail status of the suite's shared `spec` group — every
test that verifies specification-defined behavior — against the two SDKs under
`sdks/`. Generated from full suite runs (JUnit logs); re-run with
`make test SDK=<tbachert|official>` to refresh.

- **tbachert run:** 372 tests executed, **372 passing** (`spec` + `tbachert`
  groups).
- **official run:** 373 tests executed, **106 passing / 267 failing**
  (`spec` + `official` groups). Most failures (208 of 267) are gated by
  not-yet-updated config-file support: the SDK only accepts
  `file_format: '1.0-rc.2'`, while the suite uses data model version 1.2.
  Every failure pins behavior that is in scope of the official specification.

The matrix below covers the 369 shared `spec` tests; vendor-specific tests
(`TbachertSpecificTest`, `OfficialSpecificTest`) are documented in the README
and not part of this overview.

Legend: ✅ all passing · ⚠️ partially passing · ❌ fully failing · – no tests
for that mode. Each spec test is counted in exactly one cell; the "Upstream
tracking" table at the bottom accounts for all 267 failures by root cause.

## Traces

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| End-to-end span export | ✅ 1/1 | – | ✅ 1/1 | – |
| Cross-service context propagation across mixed env/config-file services | – | ✅ 1/1 | – | ❌ 0/1¹ |
| Sampling: always_on/off, trace-id ratio, parent-based, rule-based, `jaeger_remote` | ✅ 16/16 | ✅ 29/29 | ⚠️ 9/16²³ | ❌ 0/29¹ |
| Span & attribute limits (count, value length, depth) | ✅ 11/11 | ✅ 4/4 | ⚠️ 8/11²⁴ | ❌ 0/4¹ |
| Batch & simple span processors | ✅ 5/5 | ✅ 5/5 | ⚠️ 4/5⁵ | ❌ 0/5¹ |
| Span details: status, events, kinds, links, attribute value types | ✅ 5/5 | – | ✅ 5/5 | – |

¹ Blocked by the `file_format` gate: the official `sdk-configuration` package
only accepts `file_format: '1.0-rc.2'`, while the suite uses data model version
1.2, so every config-file test fails at initialization. Expected to be resolved
by [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050).  
² Lenient handling of invalid configuration: unrecognized or differently-cased
enum values (exporter, propagator, sampler, protocol names) and unparseable
values (numeric limits, `OTEL_TRACES_SAMPLER_ARG`, malformed
`OTEL_RESOURCE_ATTRIBUTES`) abort SDK initialization — or silently drop the
propagator — instead of logging a warning and falling back to the default.  
³ The `jaeger_remote` sampler is not implemented:
`OTEL_TRACES_SAMPLER=jaeger_remote` fails initialization with "unknown sampler"
(untracked upstream).  
⁴ The 2 failures are the **global** attribute limit vars
(`OTEL_ATTRIBUTE_COUNT_LIMIT`, `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT`) — declared
but not applied ([#2055](https://github.com/open-telemetry/opentelemetry-php/issues/2055));
the third is an unparseable limit aborting initialization (footnote ²). The
signal-specific `OTEL_SPAN_*` limits work.  
⁵ Queue-full drop fails: the batch processor flushes synchronously on span
end, so the queue never fills (spec deviation, untracked upstream).

## Context propagation

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Propagators: tracecontext, baggage, b3 (injection & extraction) | ✅ 17/17 | ✅ 7/7 | ⚠️ 16/17² | ❌ 0/7¹ |
| Cross-signal correlation: logs & metrics carry the active span context | ✅ 4/4 | ✅ 1/1 | ⚠️ 2/4⁶ | ❌ 0/1¹ |

⁶ All exemplar tests fail: none are captured/exported with the spec's filter
values (`trace_based`, `always_on`), and an invalid value falls back to no
exemplars instead of the default `trace_based`
([#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054)).

## Logs

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Log record content: severity, non-string & nested body types | ✅ 3/3 | – | ✅ 3/3 | – |
| Log record limits (attribute count & value length) | ✅ 3/3 | ✅ 2/2 | ✅ 3/3 | ❌ 0/2¹ |
| Batch & simple log record processors | ✅ 5/5 | ✅ 3/3 | ⚠️ 4/5⁵ | ❌ 0/3¹ |
| Log severity threshold & trace-based filtering | – | ✅ 6/6 | – | ❌ 0/6¹ |

## Metrics

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Metric pipeline: instruments, series separation, gauge/counter semantics | – | ✅ 8/8 | – | ❌ 0/8¹ |
| Exemplar filter (always_on/off, trace_based, invalid fallback) | ✅ 4/4 | ✅ 2/2 | ⚠️ 1/4⁶ | ❌ 0/2¹ |
| Temporality preference (cumulative/delta) | ✅ 1/1 | ✅ 3/3 | ❌ 0/1⁷ | ❌ 0/3¹ |
| Default histogram aggregation (exponential buckets, spec boundaries) | ✅ 1/1 | ✅ 2/2 | ❌ 0/1⁸ | ❌ 0/2¹ |
| Collection interval & periodic reader (batch size) | ✅ 1/1 | ✅ 3/3 | ❌ 0/1⁷ | ❌ 0/3¹ |
| Views: selection, renaming, attribute filtering, aggregation, composable views | – | ✅ 35/35 | – | ❌ 0/35¹ |
| Cardinality limits (overflow series) | – | ✅ 4/4 | – | ❌ 0/4¹ |

⁷ The periodic-exporting metric reader does not work
([#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884)): the
export interval env var is ignored, and delta temporality is unobservable in a
single shutdown collection.  
⁸ `OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION` is declared but
never applied (untracked upstream).

## Exporters & transport

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| OTLP HTTP exporter options: protocol, headers, compression, endpoints, timeouts, size limits, retries | ✅ 24/24 | ✅ 18/18 | ⚠️ 21/24²⁹ | ❌ 0/18¹ |
| OTLP gRPC exporter (all signals) | ✅ 12/12 | – | ❌ 0/12¹⁰ | – |
| OTLP gRPC exporter (config file) | – | ✅ 4/4 | – | ❌ 0/4¹ |
| OTLP file exporter (newline-delimited JSON on disk) | – | ✅ 1/1 | – | ❌ 0/1¹ |
| TLS: CA trust & client certificates (all signals, both protocols) | ✅ 8/8 | ✅ 6/6 | ❌ 0/8¹¹ | ❌ 0/6¹ |
| Console exporter (stdout) | ✅ 3/3 | ✅ 3/3 | ✅ 3/3 | ❌ 0/3¹ |
| Prometheus exporter (pull, translation & escaping) | ✅ 1/1 | ✅ 9/9 | ❌ 0/1¹² | ❌ 0/9¹ |

⁹ The exporter retries non-retryable `500` responses (four attempts observed);
the specification requires that all `4xx`/`5xx` codes other than 429, 502, 503
and 504 MUST NOT be retried (untracked upstream).  
¹⁰ The 12 env-based tests fail because per-signal gRPC endpoints are used as-is
(the method path is only appended to the *generic* endpoint), which aborts
initialization. The HTTP/2 exchange itself has been verified to work (a raw
C-core client delivers correct gRPC frames to the suite's capture server), so
no interop problem exists.  
¹¹ The certificate environment variables
(`OTEL_EXPORTER_OTLP[_<signal>]*_CERTIFICATE`, `_CLIENT_CERTIFICATE`,
`_CLIENT_KEY`) are declared but never passed to the transport factories, for
all signals and both protocols.  
¹² No exporter factory is registered for the protocol; structurally requires an
async runtime to serve the pull reader; excluded from upstream issue reporting
by decision.

## Resource & entities

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Resource attributes & service.name from environment | ✅ 8/8 | – | ✅ 8/8 | – |
| Entities (`OTEL_ENTITIES`, env detector) | ✅ 7/7 | ✅ 1/1 | ❌ 0/7¹³ | ❌ 0/1¹ |
| Resource detectors & attribute include/exclude (config file) | – | ✅ 15/15 | – | ❌ 0/15¹ |

¹³ The env entity detector is not implemented (untracked upstream).

## SDK-level & cross-cutting

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| SDK enablement & per-signal exporter selection (`OTEL_SDK_DISABLED`, `*_EXPORTER`) | ✅ 10/10 | ✅ 1/1 | ⚠️ 9/10² | ❌ 0/1¹ |
| Provider configurators: scope filtering (wildcards, case sensitivity, isolation) | – | ✅ 18/18 | – | ❌ 0/18¹ |
| Edge cases & leniency: empty values, invalid sampler/propagator, malformed attributes | ✅ 5/5 | – | ⚠️ 2/5² | – |
| `OTEL_LOG_LEVEL` (self-diagnostic output) | ✅ 1/1 | ✅ 1/1 | ✅ 1/1 | ❌ 0/1¹ |
| Config file basics: no-op SDK, format versioning, missing file, independent signals | – | ✅ 5/5 | – | ⚠️ 1/5¹¹⁴ |
| ID generation (random) | – | ✅ 1/1 | – | ❌ 0/1¹ |
| Variable substitution: `${}`, defaults, escaping, type coercion | – | ✅ 9/9 | – | ⚠️ 1/9¹ |
| Environment variable precedence & ignore rules in config-file mode | – | ✅ 5/5 | – | ❌ 0/5¹ |
| SDK self-observability disabled by default | – | ✅ 1/1 | – | ❌ 0/1¹ |

¹⁴ A missing configuration file causes an uncaught fatal error instead of a
reported initialization error (untracked upstream). The one passing test pins
the current no-op behavior for unsupported file formats.

## Upstream tracking (official SDK failures, by root cause)

| Failing group | Tests | Tracking |
|---|---:|---|
| File-based configuration (`file_format` 1.2 gate): all config-file tests except the two passing pins, the `jaeger_remote` config tests and the missing-file test | 208 | [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050) |
| gRPC: per-signal endpoints abort initialization (env mode) | 12 | untracked |
| Certificate env vars never wired into the transports (OTLP/HTTP, all signals) | 8 | untracked |
| Lenient handling of invalid configuration: unrecognized/case-mismatched enums and unparseable values abort init instead of warning + fallback | 10 | untracked |
| Entities (`OTEL_ENTITIES`) not implemented | 7 | untracked |
| `jaeger_remote` sampler not implemented (5 env + 2 config-file) | 7 | untracked |
| Exemplars / exemplar filter: spec values unrecognized, invalid → no exemplars; none captured or exported | 5 | [#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054) |
| Global attribute limits declared but not applied | 2 | [#2055](https://github.com/open-telemetry/opentelemetry-php/issues/2055) |
| Batch processor queue-full drop: blocking I/O on span end / log emit | 2 | untracked |
| Metric export interval / periodic reader (+ temporality dependency) | 2 | [#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884) |
| Prometheus pull exporter (needs async runtime) | 1 | not reported upstream (by decision) |
| Default histogram aggregation env var declared but not applied | 1 | untracked |
| Non-retryable HTTP `500` responses are retried | 1 | untracked |
| Missing config file: uncaught fatal error instead of a reported initialization error | 1 | untracked |
| **Total** | **267** | |

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

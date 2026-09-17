# SDK support overview

Feature-by-feature pass/fail status of the suite's shared `spec` group — every
test that verifies specification-defined behavior — against the two SDKs under
`sdks/`. Generated from full suite runs (JUnit logs); re-run with
`make test SDK=<tbachert|official>` to refresh.

- **tbachert run:** 374 tests executed, **374 passing** (`spec` + `tbachert`
  groups).
- **official run:** 376 tests executed, **107 passing / 269 failing**
  (`spec` + `official` groups). Most failures (210 of 269) are gated by
  not-yet-updated config-file support: the SDK only accepts
  `file_format: '1.0-rc.2'`, while the suite uses data model version 1.2, so
  every config-file test fails at initialization.

The matrix below covers the 371 shared `spec` tests; vendor-specific tests
(`TbachertSpecificTest`, `OfficialSpecificTest`) are documented in the README
and not part of this overview. Sections follow the specification's structure
(context propagation, then the signals in spec order: traces, metrics, logs);
rows within a section are organized by tested behavior. Since the
configuration file must support at least every option available via
environment variables, each env-based row has a config-file counterpart; rows
missing one side cover either data-model nodes without an environment
equivalent (config-only) or environment-value parsing semantics that have no
config-file equivalent (env-only).

Legend: ✅ all passing · ⚠️ partially passing · ❌ fully failing · – no tests
for that mode. Each spec test is counted in exactly one cell; the "Upstream
tracking" table at the bottom accounts for all 269 failures by root cause.
Footnotes are collected below the tables.

## Context propagation

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Propagators: tracecontext, baggage, b3 (injection & extraction) | ✅ 17/17 | ✅ 7/7 | ⚠️ 16/17¹ | ❌ 0/7³ |
| Cross-signal correlation: logs & metrics carry the active span context | ✅ 4/4 | ✅ 1/1 | ⚠️ 2/4² | ❌ 0/1³ |

## Traces

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| End-to-end span export & cross-service context propagation (incl. mixed env/config-file services) | ✅ 1/1 | ✅ 2/2 | ✅ 1/1 | ❌ 0/1³ |
| Sampling: always_on/off, trace-id ratio, parent-based, rule-based, `jaeger_remote` | ✅ 16/16 | ✅ 29/29 | ⚠️ 9/16¹⁴ | ❌ 0/29³ |
| Span & attribute limits (count, value length, depth) | ✅ 11/11 | ✅ 4/4 | ⚠️ 8/11¹⁵ | ❌ 0/4³ |
| Batch & simple span processors | ✅ 5/5 | ✅ 5/5 | ⚠️ 4/5⁶ | ❌ 0/5³ |
| Span details: status, events, kinds, links, attribute value types | ✅ 5/5 | ✅ 1/1 | ✅ 5/5 | ❌ 0/1³ |

## Metrics

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Metric pipeline: instruments, series separation, gauge/counter semantics | – | ✅ 8/8 | – | ❌ 0/8³ |
| Exemplar filter (always_on/off, trace_based, invalid fallback) | ✅ 4/4 | ✅ 2/2 | ⚠️ 1/4² | ❌ 0/2³ |
| Temporality preference (cumulative/delta) | ✅ 1/1 | ✅ 3/3 | ❌ 0/1⁷ | ❌ 0/3³ |
| Default histogram aggregation (exponential buckets, spec boundaries) | ✅ 1/1 | ✅ 2/2 | ❌ 0/1⁸ | ❌ 0/2³ |
| Collection interval & periodic reader (batch size) | ✅ 1/1 | ✅ 3/3 | ❌ 0/1⁷ | ❌ 0/3³ |
| Views: selection, renaming, attribute filtering, aggregation, composable views | – | ✅ 35/35 | – | ❌ 0/35³ |
| Cardinality limits (overflow series) | – | ✅ 4/4 | – | ❌ 0/4³ |

## Logs

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Log record content: severity, non-string & nested body types | ✅ 3/3 | ✅ 1/1 | ✅ 3/3 | ❌ 0/1³ |
| Log record limits (attribute count & value length) | ✅ 3/3 | ✅ 2/2 | ✅ 3/3 | ❌ 0/2³ |
| Batch & simple log record processors | ✅ 5/5 | ✅ 3/3 | ⚠️ 4/5⁶ | ❌ 0/3³ |
| Log severity threshold & trace-based filtering | – | ✅ 6/6 | – | ❌ 0/6³ |

## Resource & entities

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| Resource attributes & service.name | ✅ 8/8 | ✅ 5/5 | ✅ 8/8 | ❌ 0/5³ |
| Entities (`OTEL_ENTITIES`, env detector) | ✅ 7/7 | ✅ 1/1 | ❌ 0/7⁹ | ❌ 0/1³ |
| Resource detectors & attribute include/exclude (config file) | – | ✅ 10/10 | – | ❌ 0/10³ |

## Exporters & transport

| Behavior | tbachert env | tbachert config | official env | official config |
|---|---|---|---|---|
| OTLP HTTP exporter options: protocol, headers, compression, endpoints, timeouts, size limits, retries | ✅ 24/24 | ✅ 18/18 | ⚠️ 21/24¹¹⁰ | ❌ 0/18³ |
| OTLP gRPC exporter (all signals) | ✅ 12/12 | ✅ 4/4 | ❌ 0/12¹¹ | ❌ 0/4³ |
| OTLP file exporter (newline-delimited JSON on disk) | – | ✅ 1/1 | – | ❌ 0/1³ |
| TLS: CA trust & client certificates (all signals, both protocols) | ✅ 8/8 | ✅ 6/6 | ❌ 0/8¹² | ❌ 0/6³ |
| Console exporter (stdout) | ✅ 3/3 | ✅ 3/3 | ✅ 3/3 | ❌ 0/3³ |
| Prometheus exporter (pull, translation & escaping) | ✅ 1/1 | ✅ 9/9 | ❌ 0/1¹³ | ❌ 0/9³ |

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
| SDK self-observability disabled by default | – | ✅ 1/1 | – | ❌ 0/1³ |

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
by [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050).  
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
and 504 MUST NOT be retried (untracked upstream).  
¹¹ The 12 env-based tests fail because per-signal gRPC endpoints are used as-is
(the method path is only appended to the *generic* endpoint), which aborts
initialization. The HTTP/2 exchange itself has been verified to work (a raw
C-core client delivers correct gRPC frames to the suite's capture server), so
no interop problem exists.  
¹² The certificate environment variables
(`OTEL_EXPORTER_OTLP[_<signal>]*_CERTIFICATE`, `_CLIENT_CERTIFICATE`,
`_CLIENT_KEY`) are declared but never passed to the transport factories, for
all signals and both protocols.  
¹³ No exporter factory is registered for the protocol; structurally requires an
async runtime to serve the pull reader; excluded from upstream issue reporting
by decision.  
¹⁴ A missing configuration file causes an uncaught fatal error instead of a
reported initialization error (untracked upstream). The one passing test pins
the current no-op behavior for unsupported file formats.

## Upstream tracking (official SDK failures, by root cause)

| Failing group | Tests | Tracking |
|---|---:|---|
| File-based configuration (`file_format` 1.2 gate): all config-file tests except the two passing pins, the `jaeger_remote` config tests and the missing-file test | 210 | [PR #2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050) |
| gRPC: per-signal endpoints abort initialization (env mode) | 12 | untracked |
| Certificate env vars never wired into the transports (OTLP/HTTP, all signals) | 8 | untracked |
| Lenient handling of invalid configuration: unrecognized/case-mismatched enums and unparseable values abort init instead of warning + fallback | 10 | untracked |
| Entities (`OTEL_ENTITIES`) not implemented | 7 | untracked |
| `jaeger_remote` sampler not implemented (5 env + 2 config-file) | 7 | untracked |
| Exemplars / exemplar filter: spec values unrecognized, invalid → no exemplars; none captured or exported | 5 | [#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054), fix in [PR #2057](https://github.com/open-telemetry/opentelemetry-php/pull/2057) (open) |
| Global attribute limits declared but not applied | 2 | [#2055](https://github.com/open-telemetry/opentelemetry-php/issues/2055), fix in [PR #2058](https://github.com/open-telemetry/opentelemetry-php/pull/2058) (open) |
| Batch processor queue-full drop: blocking I/O on span end / log emit | 2 | untracked |
| Metric export interval / periodic reader (+ temporality dependency) | 2 | [#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884) |
| Prometheus pull exporter (needs async runtime) | 1 | not reported upstream (by decision) |
| Default histogram aggregation env var declared but not applied | 1 | untracked |
| Non-retryable HTTP `500` responses are retried | 1 | untracked |
| Missing config file: uncaught fatal error instead of a reported initialization error | 1 | untracked |
| **Total** | **269** | |

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

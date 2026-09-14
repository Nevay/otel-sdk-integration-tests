# otel-test

Integration tests for OpenTelemetry SDK configuration: every test verifies
behavior driven exclusively by standard OpenTelemetry environment variables or
a configuration file — no programmatic configuration.

The suite is SDK-independent: it interacts with the SDK under test only through
its documented configuration surface (environment variables, configuration
file) and the OTLP wire protocol, so it can be pointed at any SDK to verify
compliance. The env-based tests check specification behavior as-is; the
file-based tests use the official
[opentelemetry-configuration](https://github.com/open-telemetry/opentelemetry-configuration)
data model as supported by the configured SDK (here:
[`tbachert/otel-sdk`](https://github.com/tbachert/otel-sdk)). The only
SDK-specific glue is the child-process bootstrap that loads the SDK
(`OTEL_PHP_AUTOLOAD_ENABLED`) and the initialization error message used as a
failure guard.

## Running the tests

The repository root is itself a Composer package (the compliance suite,
`tbachert/otel-test`). Each SDK is a separate project under `sdks/`, pulling
in the suite through a local path repository to the repository root, so both
SDKs can be installed side by side — and external projects can require the
suite the same way:

```sh
make dependencies-install   # or: make dependencies-update
make test SDK=tbachert      # run the suite against tbachert/otel-sdk
make test SDK=official      # run the suite against open-telemetry/sdk
```

The test container runs on PHP 8.5 by default; the version can be overridden
at build time (minimum supported: 8.4):

```sh
PHP_VERSION=8.4 make build
```

Tests can be selected with PHPUnit groups (`--group` / `--exclude-group`,
passable via `ARGS='--group env'`):

- configuration mode: `env`, `config-file`
- signal: `traces`, `metrics`, `logs`
- `async`: tests whose server handlers use `Amp\delay`
- feature: `prometheus` (pull exporter), `entities` (`OTEL_ENTITIES`)
- SDK name (`tbachert`, `official`): tests that only apply to that specific
  SDK (vendor options, non-spec environment variables, implementation-dependent
  behavior). Each run excludes the other SDK's group.

## Tested SDKs

### [`tbachert/otel-sdk`](https://github.com/tbachert/otel-sdk)

Exact package versions pinned in `sdks/tbachert/composer.lock`.

**Scope.** All stable environment variables from the specification's SDK
configuration section, plus file-based configuration using the official
[opentelemetry-configuration](https://github.com/open-telemetry/opentelemetry-configuration)
data model (schema versions 1.0 through 1.2). Two implementation details go
beyond the spec's wording: the rejection threshold is written to the TraceState
`th` sub-key even for dropped decisions, and root spans whose trace IDs lack
the random flag get a generated explicit `rv` value — a path that cannot be
triggered through configuration.
`OTEL_LOG_LEVEL` is applied to the SDK's internal logger: at `debug`,
diagnostic messages appear on stderr; at `error`, even warnings (e.g. for an
unrecognized `OTEL_TRACES_SAMPLER` value, which is logged and ignored in
favour of the default sampler) are suppressed.

**SDK-specific configuration.** Beyond the spec surface, this SDK exposes
vendor options: non-spec environment variables (`OTEL_PHP_SHUTDOWN_TIMEOUT`,
`OTEL_PHP_EXPERIMENTAL_SPAN_SUPPRESSION_STRATEGY`), a config-file processor
node that is not part of the official data model
(`capture_code_attributes/development`), and vendor options under the schema's
`distribution:` extension point (e.g. `shutdown_timeout`). SDK self-observability
is off by default; the vendor-only `*_configurator/development` nodes enable it
per signal via `config.enabled: true`, marking all such telemetry with the scope
attribute `php.otel.sdk.self_diagnostics` (`ConfigSelfObservabilityTest` pins
the metrics side, semconv names and instrument types).
Tests that pin this surface are tagged with the group `tbachert` and are
excluded from the official run.

**Spec features not implemented by this SDK (gaps, not deviations):**

- **Zipkin exporter** (`OTEL_EXPORTER_ZIPKIN_ENDPOINT`,
  `OTEL_EXPORTER_ZIPKIN_TIMEOUT`, the `zipkin` exporter value) — deprecated in
  the specification, so no action is required.
- **`OTEL_EXPERIMENTAL_CONFIG_FILE`** — deprecated in the specification; this
  SDK reads its stable replacement, `OTEL_CONFIG_FILE`, instead (used by all
  config-file tests).

**Testability note.** gRPC over plaintext (h2c prior knowledge) cannot be
tested end-to-end by the suite itself: the amphp HTTP server only speaks
HTTP/2 over TLS (ALPN) or via the opt-in h2c UPGRADE mechanism, and no other
gRPC server implementation ships with this environment. The exporter's wire
format has been verified manually against a reference collector
(opentelemetry-collector v0.160.0, plaintext h2c and TLS alike); the suite
verifies the plaintext dial itself (the HTTP/2 connection preface on the wire)
instead.

The `jaeger_remote` sampler is implemented (env mode and config file), but
its strategy client hard-codes a default TLS context: it cannot be pointed
at the suite's self-signed test server without modifying the container's
system trust store, so it is not covered by tests.

### [`open-telemetry/sdk`](https://github.com/open-telemetry/opentelemetry-php)

Exact package versions pinned in `sdks/official/composer.lock`.

**Status.** The env-based suite is green except for the groups below; all
file-based tests are currently blocked. Tests tagged `tbachert` (options of
tbachert/otel-sdk, non-spec environment variables, implementation-dependent
timing) are excluded from this run, so every remaining failure pins behavior
that is in scope of the official specification.

**SDK-specific configuration.** Beyond the spec surface, this SDK exposes
PHP-specific environment variables: `OTEL_PHP_TRACES_PROCESSOR` /
`OTEL_PHP_LOGS_PROCESSOR` (processor selection), `OTEL_PHP_DETECTORS`
(resource detector selection), `OTEL_PHP_LOG_DESTINATION` (self-diagnostic log
destination), and `OTEL_PHP_INTERNAL_METRICS_ENABLED` (SDK self-instrumentation).
`OfficialSpecificTest` covers these; the tests are tagged with the group
`official` and are excluded from the tbachert run. The spec variable
`OTEL_LOG_LEVEL` is declared (with its known values) but not applied to the
SDK's log output.

**Currently failing groups:**

- **File-based configuration (all `config-file` tests).** The official
  `sdk-configuration` package only accepts `file_format: '1.0-rc.2'`, while the
  suite uses data model version 1.2. Expected to be resolved by
  [open-telemetry/opentelemetry-php#2050](https://github.com/open-telemetry/opentelemetry-php/pull/2050).
- **OTLP/gRPC.** A gRPC transport is available via
  [`open-telemetry/transport-grpc`](https://packagist.org/packages/open-telemetry/transport-grpc)
  (installed in `sdks/official`), but the tests still fail for two reasons:
    - Per-signal endpoints (`OTEL_EXPORTER_OTLP_<signal>_ENDPOINT`) are passed
      to the transport factory as-is, while the factory requires a full gRPC
      method path; only the generic `OTEL_EXPORTER_OTLP_ENDPOINT` gets the
      method appended. The as-is behavior is shared by all three signal
      exporter factories (`SpanExporterFactory`, `MetricExporterFactory`,
      `LogsExporterFactory`). The specification says the gRPC endpoint option
      MUST accept a URL with an `http`/`https` scheme (the usual `host:port`
      form), so standard per-signal configuration aborts SDK initialization.
    - TLS export fails certificate verification: the signal exporter
      factories never pass the `OTEL_EXPORTER_OTLP_<signal>_CERTIFICATE`,
      `_CLIENT_CERTIFICATE`, and `_CLIENT_KEY` values to the gRPC transport
      factory (which accepts them as optional parameters), so the C-core
      channel falls back to the default root store and rejects self-signed
      test certificates (`CERTIFICATE_VERIFY_FAILED: self signed
      certificate`). This is the same wiring gap as the OTLP/HTTP TLS issue,
      generalized to gRPC. The HTTP/2 exchange itself has been verified to
      work: a raw C-core client delivers correct gRPC frames to the suite's
      amphp capture server, so no interop problem exists.
- **Entity propagation (`OTEL_ENTITIES`).** The spec-mandated env entity
detector is not implemented; no upstream issue or PR tracks it yet.
- **Exemplar filter values.** The SDK's known values for
  `OTEL_METRICS_EXEMPLAR_FILTER` are `with_sampled_trace`, `all`, and `none`
  instead of the spec's `trace_based`, `always_on`, and `always_off`; spec
  values fall back to no exemplars. Tracked in
  [open-telemetry/opentelemetry-php#2054](https://github.com/open-telemetry/opentelemetry-php/issues/2054).
- **Global attribute limits.** `OTEL_ATTRIBUTE_COUNT_LIMIT` and
  `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT` are declared but not applied (the
  signal-specific `OTEL_SPAN_*` limits work). Tracked in
  [open-telemetry/opentelemetry-php#2055](https://github.com/open-telemetry/opentelemetry-php/issues/2055).
- **Lenient handling of invalid configuration.** An unknown
  `OTEL_TRACES_SAMPLER` value or malformed `OTEL_RESOURCE_ATTRIBUTES`
  aborts SDK initialization instead of logging a warning and falling back to
  the default.
- **TLS environment variables (all signals).**
  `OTEL_EXPORTER_OTLP[_<signal>]*_CERTIFICATE` and the client
  certificate/key variables are declared but not applied for any signal:
  the OTLP HTTP transport supports custom CA/client certificates, but none
  of the three signal exporter factories reads the variables or passes them
  to the transport factory.
- **Prometheus exporter.** `prometheus` is a known value of
  `OTEL_METRICS_EXPORTER` and `OTEL_EXPORTER_PROMETHEUS_HOST/PORT` are spec
  (in-development) variables, but no exporter factory is registered for the
  protocol.
- **Metric export interval.** `OTEL_METRIC_EXPORT_INTERVAL` is declared but
  not applied; the periodic reader only exports at shutdown. Tracked in
  [open-telemetry/opentelemetry-php#1884](https://github.com/open-telemetry/opentelemetry-php/issues/1884)
  ("Periodic exporting MetricReader not working"). The delta-temporality test
  (`OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE`, implemented by this
  SDK) also depends on periodic exports and fails for the same reason: a
  single shutdown collection cannot distinguish delta from cumulative.
- **Default histogram aggregation.** `OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION`
  is declared (default and enum values) but not applied; histograms always use
  explicit bucket aggregation.
- **Non-retryable HTTP responses are retried.** The OTLP/HTTP exporter
  retries `500 Internal Server Error` responses (four attempts observed);
  the specification requires that all `4xx`/`5xx` codes other than 429, 502,
  503 and 504 MUST NOT be retried.
- **Invalid exemplar filter fallback.** An unknown
  `OTEL_METRICS_EXEMPLAR_FILTER` value falls back to no exemplars at all;
  per the configuration guidance, invalid values should be treated as unset,
  i.e. the default (`trace_based`) should apply.
- **Batch processor queue-full drop.** The batch span and log record
  processors flush synchronously after every span/record (autoFlush hardcoded
  to true), so ending a span or emitting a log performs blocking I/O on the
  calling thread — against the API spec's "MUST NOT perform blocking I/O" for
  `End()` and the SDK spec's "should not block" for `OnEnd`/`OnEmit`. In a
  single-threaded scenario the bounded queue therefore never fills, making
  the spec-mandated queue-full drop behavior unobservable.

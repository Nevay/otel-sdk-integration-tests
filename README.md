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

Tests can be selected with PHPUnit groups (`--group` / `--exclude-group`,
passable via `ARGS='--group env'`):

- configuration mode: `env`, `config-file`
- signal: `traces`, `metrics`, `logs`
- `async`: tests whose server handlers use `Amp\delay`
- `vendor-specific`: tests that pin behavior outside the scope of the official
  specification (tbachert/otel-sdk options, non-spec environment variables,
  implementation-dependent timing). The official SDK run excludes this group;
  the tbachert run includes it.

## Tested SDKs

### [`tbachert/otel-sdk`](https://github.com/tbachert/otel-sdk)

Exact package versions pinned in `sdks/tbachert/composer.lock`.

**Scope.** All stable environment variables from the specification's SDK
configuration section, plus file-based configuration using the official
[opentelemetry-configuration](https://github.com/open-telemetry/opentelemetry-configuration)
data model — this SDK accepts schema versions 1.0 through 1.2 (its `file_format`
check) and uses the schema's `distribution:` extension point for vendor-specific
options. The specification's experimental entity propagation (`OTEL_ENTITIES`)
is implemented as a built-in `env` resource detector (active in env mode,
selectable under `resource.detection/development` in config-file mode); entities
are exported with OTLP as references into the resource attributes.

**Spec features not implemented by this SDK (gaps, not deviations):**

- **Zipkin exporter** (`OTEL_EXPORTER_ZIPKIN_ENDPOINT`,
  `OTEL_EXPORTER_ZIPKIN_TIMEOUT`, the `zipkin` exporter value) — deprecated in
  the specification, so no action is required.
- **`OTEL_EXPERIMENTAL_CONFIG_FILE`** — deprecated in the specification; this
  SDK reads its stable replacement, `OTEL_CONFIG_FILE`, instead (used by all
  config-file tests).

**Testability note.** gRPC over plaintext (h2c prior knowledge) cannot be
tested end-to-end in this environment because no available gRPC server
implementation interoperates with this SDK's amphp-based HTTP/2 client; the
suite verifies the plaintext dial itself (the HTTP/2 connection preface on the
wire) instead.

### [`open-telemetry/sdk`](https://github.com/open-telemetry/opentelemetry-php)

Exact package versions pinned in `sdks/official/composer.lock`.

**Status.** The env-based suite is green except for the groups below; all
file-based tests are currently blocked. Tests tagged `vendor-specific`
(tbachert-only options, non-spec environment variables, implementation-dependent
timing) are excluded from this run, so every remaining failure pins behavior
that is in scope of the official specification.

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
    - End-to-end export is blocked by HTTP/2 interop in this environment: the
      C-core gRPC client (required by `transport-grpc`) and the amphp-based
      HTTP/2 server used by the suite's capture collector do not
      interoperate (the request never reaches the server handler; the client
      reports "Deadline Exceeded").
- **Entity propagation (`OTEL_ENTITIES`).** The spec-mandated env entity
detector is not implemented (upstream PR in progress).
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
  not applied; the periodic reader only exports at shutdown.

# otel-integration-tests

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
`tbachert/otel-integration-tests`). Each SDK is a separate project under `sdks/`, pulling
in the suite through a local path repository to the repository root, so both
SDKs can be installed side by side — and external projects can require the
suite the same way:

```sh
make dependencies-install   # or: make dependencies-update
make test SDK=tbachert      # run the suite against tbachert/otel-sdk
make test SDK=official      # run the suite against open-telemetry/sdk
```

Run only one suite at a time: several tests are timing-sensitive (periodic
collection intervals versus async delays), and running two suites in parallel
on the same host can make them fail spuriously.

The test container runs on PHP 8.5 by default; the version can be overridden
at build time (minimum supported: 8.4):

```sh
PHP_VERSION=8.4 make build
```

Tests can be selected with PHPUnit groups (`--group` / `--exclude-group`,
passable via `ARGS='--group env'`):

- configuration mode: `env`, `config-file`
- signal: `traces`, `metrics`, `logs`
- context propagation: `propagation` (propagators, cross-signal correlation)
- `async`: tests whose server handlers use `Amp\delay`
- feature: `prometheus` (pull exporter), `entities` (`OTEL_ENTITIES`)
- feature area: `sampler`, `jaeger`, `resource`, `view`, `aggregation`
- `spec`: every test that verifies specification-defined behavior. Each SDK
  run executes the `spec` group plus its own SDK-name group (`tbachert`,
  `official`), which holds tests that only apply to that specific SDK (vendor
  options, non-spec environment variables, implementation-dependent behavior).

**Dependency note.** The root `composer.json` pins `open-telemetry/api` to
the unreleased baggage fix — `dev-main#b2bc26bf… as 1.10.x-dev`, the commit
of [open-telemetry/opentelemetry-php#2056](https://github.com/open-telemetry/opentelemetry-php/pull/2056)
(merged, not yet part of a release) — because the newest api release (1.10.0)
drops baggage list-members whose values are percent-encoded. Packagist serves
`open-telemetry/api` from the read-only `opentelemetry-php/api` mirror of the
monorepo's `src/API`, so the pin references that mirror's commit rather than
the monorepo's. Replace the pin with the next stable api release once it
ships.

## Tested SDKs

### [`tbachert/otel-sdk`](https://github.com/tbachert/otel-sdk)

Exact package versions pinned in `sdks/tbachert/composer.lock`.

**Scope.** All stable environment variables from the specification's SDK
configuration section, plus file-based configuration using the official
[opentelemetry-configuration](https://github.com/open-telemetry/opentelemetry-configuration)
data model (version 1.2, including format-version handling for newer and
unknown versions).
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
per signal via `config.enabled: true` (the enablement pin in
`TbachertSpecificTest` verifies the exported semconv metric names and
instrument types).
The tests that pin this surface live in `TbachertSpecificTest`, tagged with
the group `tbachert`; they run in the tbachert run only.

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

The `jaeger_remote` sampler is implemented (env mode and config file).
Plaintext (`http://`) endpoints are covered end to end: the suite runs an
in-process h2c gRPC peer (see `JaegerSamplingServer`) serving the Jaeger
remote sampling API, and verifies all three strategy types (probability,
rate limiting, per-operation) plus the initial-sampler fallback while the
backend is unreachable. An `https://` endpoint would use default
certificate verification and thus cannot reach the suite's self-signed test
server without modifying the container's system trust store.

### [`open-telemetry/sdk`](https://github.com/open-telemetry/opentelemetry-php)

Exact package versions pinned in `sdks/official/composer.lock`.

**Status.** The env-based suite is green except for a handful of groups; all
file-based tests are currently blocked because the SDK only accepts
`file_format: '1.0-rc.2'` (the suite uses data model version 1.2). The current
per-feature pass/fail matrix and the failure breakdown by root cause live in
[`sdk-support-overview.md`](sdk-support-overview.md).

**SDK-specific configuration.** Beyond the spec surface, this SDK exposes
PHP-specific environment variables: `OTEL_PHP_TRACES_PROCESSOR` /
`OTEL_PHP_LOGS_PROCESSOR` (processor selection), `OTEL_PHP_DETECTORS`
(resource detector selection), `OTEL_PHP_LOG_DESTINATION` (self-diagnostic log
destination), and `OTEL_PHP_INTERNAL_METRICS_ENABLED` (SDK self-instrumentation).
`OfficialSpecificTest` covers these; its tests are tagged with the group
`official` and run in the official run only. The spec variable
`OTEL_LOG_LEVEL` is applied to the SDK's self-diagnostic log output, which
respects the level for warnings and initialization errors alike (`none`
suppresses all of it).


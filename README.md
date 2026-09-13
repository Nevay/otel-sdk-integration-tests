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

```sh
docker compose run --rm --no-deps php vendor/bin/phpunit
```

Tests can be selected with PHPUnit groups (`--group` / `--exclude-group`):

- configuration mode: `env`, `config-file`
- signal: `traces`, `metrics`, `logs`
- `async`: tests whose server handlers use `Amp\delay`

## Tested SDKs

### [`tbachert/otel-sdk`](https://github.com/tbachert/otel-sdk)

Exact package versions pinned in `composer.lock`.

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

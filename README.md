# otel-test

Integration tests for the configuration of [`tbachert/otel-sdk`](https://github.com/tbachert/otel-sdk):
every test verifies behavior driven exclusively by standard OpenTelemetry environment
variables or the SDK's configuration file — no programmatic configuration.

## Running the tests

```sh
docker compose run --rm --no-deps php vendor/bin/phpunit
```

Tests can be selected with PHPUnit groups (`--group` / `--exclude-group`):

- configuration mode: `env`, `config-file`
- signal: `traces`, `metrics`, `logs`
- `async`: tests whose server handlers use `Amp\delay`

## Spec compliance notes

**Scope.** All stable environment variables from the specification's SDK
configuration section, plus the SDK's file-based configuration schema. Note that
the SDK's configuration file format is its own (with a `file_format` field and a
top-level `distribution:` node), not the official
[opentelemetry-configuration](https://github.com/open-telemetry/opentelemetry-configuration)
spec format, which is still experimental; conformance here means conformance to
the SDK's documented schema.

**Deviations found while building this suite — all resolved:**

1. **TLS CA file was ineffective** (`OTEL_EXPORTER_OTLP_*_CERTIFICATE`,
   `tls.ca_file`): the loaders passed a PEM *file* to amphp's `withCaPath()`,
   which expects a directory of hashed certificates, instead of `withCaFile()`.
   Fixed in the SDK; covered by `TlsTest` (including mTLS).
2. **Default histogram boundaries omitted the spec's `750` bucket.** Fixed in
   the SDK; defaults now match `[0, 5, 10, 25, 50, 75, 100, 250, 500, 750,
   1000, 2500, 5000, 7500, 10000]` exactly.
3. **OTLP/JSON ID encoding** — initially reported as a deviation (hex vs
   base64), but re-verification showed it is spec-compliant: OTLP 1.11.0
   explicitly deviates from the proto3 JSON mapping and mandates hex for
   trace/span IDs in all OTLP messages.

**Spec features not implemented by the SDK (gaps, not deviations):**

- **Zipkin exporter** (`OTEL_EXPORTER_ZIPKIN_ENDPOINT`,
  `OTEL_EXPORTER_ZIPKIN_TIMEOUT`, the `zipkin` exporter value) — deprecated in
  the specification, so no action is required.
- **`OTEL_ENTITIES`** (experimental) — the specification requires an
  `EnvEntityDetector` that associates entities from the environment variable
  with the resource; the SDK does not read the variable. A userland
  `ResourceDetector` can fill this gap (register it with the SPI
  `ServiceLoader` before the SDK's autoload-time configuration runs).
- **`OTEL_EXPERIMENTAL_CONFIG_FILE`** — deprecated in the specification; the
  SDK reads its stable replacement, `OTEL_CONFIG_FILE`, instead (used by all
  config-file tests).

**Testability note.** gRPC over plaintext (h2c prior knowledge) cannot be
tested end-to-end in this environment because no available gRPC server
implementation interoperates with the SDK's amphp-based HTTP/2 client; the
suite verifies the plaintext dial itself (the HTTP/2 connection preface on the
wire) instead.

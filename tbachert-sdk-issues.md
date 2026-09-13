# Issues found in `tbachert/otel-sdk`

Verified against the versions installed via composer in this project (see
`composer.lock`). Each entry includes a reproduction sketch so it can be
re-checked after an SDK upgrade.

## 1. [Fixed] OTLP TLS CA certificate env vars were ineffective (`OTEL_EXPORTER_OTLP_*_CERTIFICATE`)

**Status:** fixed — verified 2026-09-13 against the updated vendor: both the
env-mode loaders (`ConfigEnv/*/{Trace,Metrics,Logs}ExporterLoaderOtlp.php`) and
the config-file exporters now call `ClientTlsContext::withCaFile()` (PHP stream
option `cafile`) instead of `withCaPath()`. Covered by `tests/TlsTest.php`,
which exports over HTTPS to a self-signed collector in both configuration
modes.

**Severity (was):** high — any export over HTTPS to a server with a non-public
(e.g. self-signed or private-CA) certificate failed verification, and the
documented way to trust that CA did nothing.

**Affected configuration surface (all call `ClientTlsContext::withCaPath()`):**

- env: `OTEL_EXPORTER_OTLP_CERTIFICATE` and the per-signal
  `OTEL_EXPORTER_OTLP_{TRACES,METRICS,LOGS}_CERTIFICATE`
  (`ConfigEnv/{Trace,Metrics,Logs}/*ExporterLoaderOtlp.php`)
- config file: `tls: {ca_file: ...}` on all OTLP exporters
  (`Config/{Trace,Metrics,Logs}/SpanExporterOtlpHttp.php`,
  `MetricExporterOtlpHttp.php`, `LogRecordExporterOtlpHttp.php`, and the gRPC
  variants)

**Root cause:** the loaders pass the PEM *file* to
`Amp\Socket\ClientTlsContext::withCaPath()`. In `amphp/socket` v3 that option
is translated to PHP stream context option **`capath`**
(`ClientTlsContext::toStreamContextArray()`), which PHP interprets as a
*directory of hashed CA certificates* (see the `ssl.capath` stream option
docs), not as a PEM file. The file path is therefore never loaded as a trust
anchor; verification falls back to the system CA bundle only.

**Reproduction:**

1. Run an OTLP/HTTP collector over TLS with a self-signed certificate for
   `127.0.0.1` (IP SAN).
2. Export any span with
   `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=https://127.0.0.1:<port>/v1/traces` and
   `OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE=/path/to/self-signed-cert.pem`.
3. The export fails on every retry with:

   ```
   Amp\Socket\TlsException: TLS negotiation failed:
   stream_socket_enable_crypto(): SSL operation failed ...
   error:0A000086:SSL routines::certificate verify failed
   ```

   while a plain PHP stream client given the same file as `cafile` verifies
   fine.

**Expected:** the certificate from the env var / config key is used as a trust
anchor (i.e. mapped to `cafile`, or loaded into an `SSL_CTX`), so exports to
private CAs work.

**Note:** the client-certificate variables
(`OTEL_EXPORTER_OTLP_*_CLIENT_CERTIFICATE` / `_CLIENT_KEY`) map to
`withCertificate()` and are not affected by this specific mismatch; they are
still not covered end-to-end (would require a collector that demands client
certificates).

## 2. [Not a bug] OTLP/JSON ID encoding: hex is spec-compliant; base64 in this suite's captures is a harness artifact

An earlier revision of this file reported span IDs as "hex in env mode,
base64 in config-file mode" and claimed the specification required base64.
That was wrong on both counts; re-verified 2026-09-13 against the current
vendor with raw wire captures (no proto round-trip).

**Specification** (OTLP 1.11.0, "JSON Protobuf Encoding"): OTLP/JSON uses the
proto3 JSON mapping *with an explicit deviation*:
> The `traceId` and `spanId` byte arrays are represented as case-insensitive
> **hex-encoded strings**; they are not base64-encoded as is defined in the
> standard Protobuf JSON Mapping. Hex encoding is used for traceId and spanId
> fields in all OTLP Protobuf messages, e.g., the Span, Link, LogRecord,
> etc. messages.

**Verified behavior of the SDK's native OTLP/JSON exports** (raw request
bodies):

| message | `traceId` / `spanId` |
|---|---|
| Span | hex, e.g. `d1616dad1223703553e92dff6a4bebaa` ✓ |
| LogRecord (emitted in span context) | hex, identical to the span's ✓ |
| Exemplar | hex ✓ |

All spec-compliant and consistent with each other. No SDK action needed.

**Why this suite still sees base64 IDs:** the harness captures every export
as a protobuf message and re-serializes it to JSON with the *canonical*
proto3 mapping (which has no knowledge of the OTLP hex deviation). Exports
sent as OTLP/protobuf therefore appear with base64 `traceId`/`spanId`, while
exports sent as native OTLP/JSON appear with hex. Tests that compare IDs
across signals normalize with `bin2hex(base64_decode(...))` or accept either
representation for exactly this reason.

## 3. [Fixed] Default histogram boundaries omitted the spec's 750 bucket

**Status:** fixed — verified 2026-09-13 against the updated vendor: a
histogram without explicit boundaries now exports `explicitBounds` of
`[0, 5, 10, 25, 50, 75, 100, 250, 500, 750, 1000, 2500, 5000, 7500, 10000]`,
matching the specification exactly.

**Severity (was):** low — data was still binned correctly relative to the
SDK's own boundaries, but the default bucketing differed from the
specification.

**Specification** (metrics SDK, Explicit Bucket Histogram Aggregation):
> Boundaries default: `[ 0, 5, 10, 25, 50, 75, 100, 250, 500, 750, 1000,
> 2500, 5000, 7500, 10000 ]` — "SDKs SHOULD use the default value when
> boundaries are not explicitly provided."

**Observed behavior:** a histogram created without explicit boundaries
exports `explicitBounds` of
`[0, 5, 10, 25, 50, 75, 100, 250, 500, 1000, 2500, 5000, 7500, 10000]` —
the `750` boundary is missing (everything else matches the spec list,
including its truncation at `10000`).

**Impact:** values in `(500, 1000]` land in a single bucket instead of two;
downstream consumers expecting the spec's default buckets get different
histograms from other SDKs for identical data.

**Expected:** `[0, 5, 10, 25, 50, 75, 100, 250, 500, 750, 1000, 2500, 5000,
7500, 10000]`.

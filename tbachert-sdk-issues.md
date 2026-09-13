# Issues found in `tbachert/otel-sdk`

Verified against the versions installed via composer in this project (see
`composer.lock`). Each entry includes a reproduction sketch so it can be
re-checked after an SDK upgrade.

## 1. OTLP TLS CA certificate env vars are ineffective (`OTEL_EXPORTER_OTLP_*_CERTIFICATE`)

**Severity:** high — any export over HTTPS to a server with a non-public (e.g.
self-signed or private-CA) certificate fails verification, and the documented
way to trust that CA does nothing.

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
`withCertificate()` and are not affected by this specific mismatch, but they
could not be verified end-to-end either (see below).

### Follow-up: TLS test coverage in this suite is blocked

Because of the bug above, no positive TLS test can be written for the exporter
(the only trust anchor the SDK accepts is the system CA bundle). The test
harness *server* side was verified to work (Amp `SocketHttpServer` +
`ServerTlsContext` with a self-signed cert serves HTTPS correctly), so once
the SDK bug is fixed, a TLS test can be added quickly.

## 2. OTLP/JSON trace and span IDs are hex in env mode, base64 in config-file mode

**Severity:** medium — the two configuration modes of the same SDK produce
different wire formats for the same bytes fields; one of them deviates from
the specification.

**Specification:** OTLP over HTTP with JSON encoding uses the canonical
proto3 JSON mapping, where `bytes` fields (including `trace_id` and
`span_id`) are encoded as **base64** strings.

**Observed behavior** (verified by exporting spans/logs in both modes to the
same collector):

| mode | span `traceId`/`spanId` | log `traceId`/`spanId` |
|---|---|---|
| env (`OTEL_EXPORTER_OTLP_*_ENDPOINT`) | hex, e.g. `ab0f5ec184a547f3d7dede62581573b6` | base64, e.g. `mum6JhpK4zxCeVVIbsoeoQ==` |
| config file (`file_format: "1.2"`) | base64 | base64 |

So in env mode even the span IDs and log IDs of *the same trace* use
different encodings within a single export, and both modes disagree with
each other.

**Impact:** consumers that strictly follow the OTLP/JSON spec (base64) fail
to parse span IDs from env-mode exports; tests in this suite therefore
normalize with `bin2hex(base64_decode(...))` / accept-either assertions.

**Expected:** both modes emit base64 for all bytes fields, per the proto3
JSON mapping.

## 3. Default histogram boundaries omit the spec's 750 bucket

**Severity:** low — data is still binned correctly relative to the SDK's own
boundaries, but the default bucketing differs from the specification.

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

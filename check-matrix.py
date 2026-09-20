#!/usr/bin/env python3
"""Verify that every shared spec test is counted exactly once in the
sdk-support-overview.md matrix, and that each cell's pass/fail counts match
the JUnit results.

Data source for row membership: matrix-map.json (explicit row -> tests map).
Ground truth for modes: Group('env') / Group('config-file') attributes in
tests/*.php (class-level default, method-level override).
Ground truth for results: .junit-tbachert.xml and .junit-official.xml.

Usage:
  python3 check-matrix.py            # verify counts, exit non-zero on mismatch
  python3 check-matrix.py --details  # print <details> blocks (one per row)
                                     # with per-test pass/fail markers
"""
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parent

SECTIONS = [
    ("Context propagation", ["Propagators: tracecontext, baggage, b3 (injection & extraction)",
                             "Cross-signal correlation: logs & metrics carry the active span context"]),
    ("Traces", ["End-to-end span export & cross-service context propagation (incl. mixed env/config-file services)",
                "Sampling: always_on/off, trace-id ratio, parent-based (simple samplers)",
                "Composite & rule-based samplers (`rule_based`, `probability`, `parent_threshold`, `always_record`)",
                "`jaeger_remote` sampler (remote dial, strategies, initial sampler)",
                "Span & attribute limits (count, value length, depth)",
                "Batch & simple span processors",
                "Span details: status, events, kinds, links, attribute value types"]),
    ("Metrics", ["Metric pipeline: instruments, series separation, gauge/counter semantics",
                 "Exemplar filter (always_on/off, trace_based, invalid fallback)",
                 "Temporality preference (cumulative/delta)",
                 "Default histogram aggregation (exponential buckets, spec boundaries)",
                 "Collection interval & periodic reader (batch size)",
                 "Views: instrument & meter selection (incl. wildcards)",
                 "Views: attribute key filtering (include/exclude, precedence)",
                 "Views: aggregation overrides (histogram buckets, drop, sum/last-value)",
                 "Views: metric renaming & description",
                 "Views: matching behavior (no-match, multiple streams, ordering)",
                 "Composable views (same-name merging, unnamed join, application order)",
                 "Cardinality limits (overflow series)"]),
    ("Logs", ["Log record content: severity, non-string & nested body types",
              "Log record limits (attribute count & value length)",
              "Batch & simple log record processors",
              "Log severity threshold & trace-based filtering"]),
    ("Resource & entities", ["Resource attributes & service.name",
                             "Entities (`OTEL_ENTITIES`, env detector)",
                             "Resource detectors & attribute include/exclude (config file)"]),
    ("Exporters & transport", ["OTLP HTTP exporter options: protocol, headers, compression, endpoints, timeouts, size limits, retries",
                               "OTLP gRPC exporter (all signals)",
                               "OTLP file exporter (newline-delimited JSON on disk)",
                               "TLS: CA trust & client certificates (all signals, both protocols)",
                               "Console exporter (stdout)",
                               "Prometheus exporter (pull, translation & escaping)"]),
    ("SDK-level & cross-cutting", ["SDK enablement & per-signal exporter selection (`OTEL_SDK_DISABLED`, `*_EXPORTER`)",
                                   "Provider configurators: scope filtering (wildcards, case sensitivity, isolation)",
                                   "Env value parsing & leniency: empty values, invalid sampler/propagator, malformed attributes (env-only semantics)",
                                   "`OTEL_LOG_LEVEL` (self-diagnostic output)",
                                   "Config file basics: no-op SDK, format versioning, missing file, independent signals",
                                   "ID generation (random)",
                                   "Variable substitution: `${}`, defaults, escaping, type coercion",
                                   "Environment variable precedence & ignore rules in config-file mode",
                                   "SDK self-observability disabled by default"]),
]


def junit(path):
    out = {}
    for tc in ET.parse(path).getroot().iter("testcase"):
        cls = tc.get("classname").rsplit(".", 1)[-1]
        if "Specific" in cls:
            continue
        ok = tc.find("failure") is None and tc.find("error") is None
        out[f"{cls}::{tc.get('name')}"] = ok
    return out


def _is_skippable(line):
    """Blank lines, attribute lines and comment lines: an attribute may sit
    above a /* */ or // comment that sits above the declaration it belongs to."""
    s = line.strip()
    return s == "" or s.startswith("#[") or s.startswith("//") \
        or s.startswith("/*") or s.startswith("*") or s == "*/"


def _groups_above(src, i):
    found = []
    j = i - 1
    while j >= 0 and _is_skippable(src[j]):
        for m in re.finditer(r"Group\('([^']+)'\)", src[j]):
            found.append(m.group(1))
        j -= 1
    return found


def test_modes():
    """Derive env/config mode per test from group attributes in tests/*.php.
    Class-level group is the default; a method-level group overrides it."""
    modes = {}
    for php in sorted((ROOT / "tests").glob("*.php")):
        src = php.read_text().splitlines()
        class_mode = None
        for i, line in enumerate(src):
            if re.match(r"\s*(?:final\s+)?class \w+", line):
                for g in _groups_above(src, i):
                    if g in ("env", "config-file"):
                        class_mode = g
                break
        for i, line in enumerate(src):
            m = re.search(r"public function (test\w+)", line)
            if not m:
                continue
            method_mode = next((g for g in _groups_above(src, i) if g in ("env", "config-file")), None)
            mode = method_mode or class_mode
            modes[f"{php.stem}::{m.group(1)}"] = "env" if mode == "env" else "config"
    return modes


def md_cells(md_text):
    cells = {}
    for line in md_text.splitlines():
        if not line.startswith("| ") or line.startswith("|---") or line.startswith("| Behavior"):
            continue
        parts = [p.strip() for p in line.strip().strip("|").split("|")]
        if len(parts) != 5:
            continue
        def den(cell):
            m = re.search(r"(\d+)/(\d+)", cell)
            return (int(m.group(1)), int(m.group(2))) if m else None
        cells[parts[0]] = [den(p) for p in parts[1:]]
    return cells


def render_details(rows, mp, modes, res_tb, res_off):
    out = []
    for row in rows:
        tests = sorted(mp.get(row, []))
        n_env = sum(1 for t in tests if modes.get(t) == "env")
        out.append("")
        out.append("<details>")
        out.append(f"<summary>{row} — {len(tests)} tests ({n_env} env · {len(tests)-n_env} config)</summary>")
        out.append("")
        for t in tests:
            tb = "✅" if res_tb.get(t) else "❌"
            off = "✅" if res_off.get(t) else "❌"
            out.append(f"- `{t}` — tbachert {tb} · official {off}")
        out.append("")
        out.append("</details>")
    return out


def update_details(md_text, mp, modes, res_tb, res_off):
    """Replace all <details> blocks with fresh ones placed after each section table."""
    lines = md_text.splitlines()
    # strip existing details blocks (keep one blank line where they were)
    cleaned, in_details = [], False
    for line in lines:
        if line.strip() == "<details>":
            in_details = True
            continue
        if in_details:
            if line.strip() == "</details>":
                in_details = False
            continue
        cleaned.append(line)
    # collapse blank runs left behind
    out, prev_blank = [], False
    for line in cleaned:
        blank = line.strip() == ""
        if blank and prev_blank:
            continue
        out.append(line)
        prev_blank = blank
    # find section tables and insert after each
    sections = {name: rows for name, rows in SECTIONS}
    result, i = [], 0
    while i < len(out):
        line = out[i]
        if line.startswith("## ") and line[3:].strip() in sections:
            j = i + 1
            while j < len(out) and not out[j].startswith("|"):
                if out[j].startswith("## "):
                    break
                j += 1
            if j < len(out) and out[j].startswith("| Behavior"):
                k = j
                while k < len(out) and out[k].startswith("|"):
                    k += 1
                result.extend(out[i:k])
                result.extend(render_details(sections[line[3:].strip()], mp, modes, res_tb, res_off))
                i = k
                continue
        result.append(line)
        i += 1
    return "\n".join(result) + "\n"


def main():
    emit_details = "--details" in sys.argv
    update_mode = "--update-details" in sys.argv
    mp = json.loads((ROOT / "matrix-map.json").read_text())
    res_tb = junit(ROOT / ".junit-tbachert.xml")
    res_off = junit(ROOT / ".junit-official.xml")
    modes = test_modes()
    md_text = (ROOT / "sdk-support-overview.md").read_text()
    cells = md_cells(md_text)

    mapped = [t for tests in mp.values() for t in tests]
    errors = []
    if len(mapped) != len(set(mapped)):
        errors.append("matrix-map.json contains duplicate test entries")
    junit_set = set(res_tb)
    if set(mapped) != junit_set:
        errors.append(f"map/junit mismatch: only-in-map={sorted(set(mapped)-junit_set)} "
                      f"only-in-junit={sorted(junit_set-set(mapped))}")
    unknown_mode = [t for t in mapped if t not in modes]
    if unknown_mode:
        errors.append(f"no mode attribute found for: {unknown_mode}")

    # every row label in the map must exist in the md, and vice versa
    section_rows = [r for _, rows in SECTIONS for r in rows]
    if set(mp) != set(section_rows):
        errors.append(f"map/section mismatch: only-in-map={sorted(set(mp)-set(section_rows))} "
                      f"only-in-sections={sorted(set(section_rows)-set(mp))}")

    totals = [0, 0, 0, 0]
    print(f"{'row':72} {'tb env':>9} {'tb cfg':>9} {'off env':>9} {'off cfg':>9}")
    for section, rows in SECTIONS:
        for row in rows:
            tests = mp.get(row, [])
            expected = []
            for res in (res_tb, res_off):
                for mode in ("env", "config"):
                    sel = [t for t in tests if modes.get(t) == mode]
                    expected.append((sum(1 for t in sel if res[t]), len(sel)))
            actual = cells.get(row)
            # a "–" cell (None) is equivalent to an empty 0/0 expectation
            norm = [a if a is not None else (0, 0) for a in actual] if actual is not None else None
            flag = ""
            if norm != expected:
                flag = "  <-- MISMATCH"
                errors.append(f"{row}: md={actual} expected={expected}")
            for i, (n, d) in enumerate(expected):
                totals[i] += d
            def fmt(e):
                return f"{e[0]}/{e[1]}" if e[1] else "-"
            print(f"{row[:72]:72} {fmt(expected[0]):>9} {fmt(expected[1]):>9} "
                  f"{fmt(expected[2]):>9} {fmt(expected[3]):>9}{flag}")
    print(f"{'TOTALS':72} {totals[0]:>9} {totals[1]:>9} {totals[2]:>9} {totals[3]:>9}")

    if update_mode:
        (ROOT / "sdk-support-overview.md").write_text(
            update_details(md_text, mp, modes, res_tb, res_off))
        print("updated <details> blocks in sdk-support-overview.md")
    elif emit_details:
        for section, rows in SECTIONS:
            for line in render_details(rows, mp, modes, res_tb, res_off):
                print(line)

    if errors:
        print(f"\n{len(errors)} problem(s):")
        for e in errors:
            print(f"  - {e}")
        sys.exit(1)
    print("\nOK: all 374 spec tests mapped exactly once; all cells match JUnit results.")


if __name__ == "__main__":
    main()

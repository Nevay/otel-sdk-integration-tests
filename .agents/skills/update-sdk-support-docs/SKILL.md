---
name: update-sdk-support-docs
description: Refresh README.md and sdk-support-overview.md in the otel-integration-tests repo after the test state changes — new/removed/renamed spec tests, composer updates of an SDK's vendor dependencies, or upstream fix PRs landing. Use when the user asks to update the support overview, document new test coverage, or reconcile docs with a fresh test run.
---

# Update SDK support docs (README + sdk-support-overview)

This repo is a cross-SDK OpenTelemetry spec-compliance suite for two PHP SDKs:
`tbachert/otel-sdk` (custom) and `open-telemetry/sdk` ("official"). **Neither SDK
has a "must be green" requirement; the requirement is spec conformance.** tbachert
currently passes the whole suite and official does not — that is an observed
outcome, not a goal, and it is not a reason to weaken a test. See "Spec
conformance outranks a green tbachert run" below.

The docs that must stay in sync with the tests:

- `sdk-support-overview.md` — behavior matrix per SDK × config mode, upstream
  tracking table, caveats. After each section table there is a collapsed
  `<details>` list repeating every test of that row with per-SDK ✅/❌ markers
  (generated, see below).
- `README.md` — SDK-independent project description, group list, per-SDK status.
- `matrix-map.json` + `check-matrix.py` — explicit row→test mapping and its
  verifier. They are the single source of truth for matrix accounting.

Every path in this skill is repository-relative: resolve it against the repository
root, which is the directory holding `composer.json`, `Makefile`,
`sdk-support-overview.md`, `matrix-map.json`, and the two SDK projects under
`sdks/`. Run every command from that root. Never refer to a host path outside the
repository.

## Repo facts (do not rediscover)

- Branch: `main`.
- Both SDK projects consume this harness as a composer **path repository**:
  `tbachert/otel-integration-tests` in `sdks/*/composer.json` points at the
  repository root, and each project installs it as a symlink. Shared requirements
  therefore belong in the root `composer.json`; `sdks/*/composer.json` only
  declares that SDK's own packages.
- All PHP, composer, and phpunit execution happens **inside Docker**, wrapped by
  the `Makefile`. The compose service mounts the repository root as the
  container's working directory (see `docker-compose.yaml`), so paths inside the
  container are the same repository-relative paths. The container sees nothing
  outside the repository: host scratch directories are not visible in it, so any
  log path you pass must be inside the project, e.g.

  ```bash
  docker compose run --rm --no-deps php sh -c "cd sdks/<sdk> && vendor/bin/phpunit --log-junit ../../.junit-check.xml"
  ```

- **Makefile targets run on the HOST** (they invoke docker compose themselves).
  Never run `make …` inside the container. Targets: `php` (interactive shell),
  `build`, `dependencies-install`, `dependencies-update`,
  `test SDK=<tbachert|official> [ARGS=…]`, `test-all`.
- `build` takes a `PHP_VERSION` build argument; the default comes from the
  `Dockerfile`. Record the PHP version a run actually used (`php -v` in the
  container) whenever you report results.
- `make test-all` runs both full suites and writes `.junit-tbachert.xml` /
  `.junit-official.xml` (regenerated artifacts). A clean run takes ~9 min.
  **Run only one suite at a time** — several tests are timing-sensitive.
- Test selection is include-based via PHPUnit groups: each SDK's `phpunit.xml`
  includes the shared `spec` group plus its own SDK-name group. The `spec` group
  must never reference vendor-specific env vars or config nodes (use
  spec-compliant processor/reader export timeouts as timing guards instead).
- JUnit XML exports **dot-separated classnames** (`Nevay.OTelTest.GrpcTest`), not
  backslashes. Parse with `python3` stdlib (`xml.etree.ElementTree`).
- `tbachert/otel-sdk` is a meta package: SDK changes require a full
  `composer update` in `sdks/tbachert` (never `--optimize-autoloader`; symlink
  cycle). After any vendor change: diff the relevant vendor files, re-run the
  affected tests, then run the full suite.

## Workflow

### 1. Establish what changed

Determine the trigger: new/removed/renamed spec tests, group/tag changes, a
`composer update` of one or both SDKs, or an upstream fix landing (check PR state
first — draft/open/merged). This decides which parts of the docs move.

### Spec conformance outranks a green tbachert run

A test is in the suite because the spec requires the behavior, not because an SDK
passes it. If a spec-conformant test fails on tbachert:

1. Keep it. Do not relax its expectations, reclassify it as vendor-specific, or
   move it out of the shared `spec` group to preserve a green run.
2. Warn about it explicitly in your reply: how many tests, which
   `Class::testMethod` names, and whether the cause is a tbachert SDK gap or a
   defect in the test itself.
3. Account for it in the docs: those tbachert cells become ⚠️/❌ with a footnote
   naming the cause, and the header's tbachert run counts show the failures next
   to the official ones.
4. Verify before blaming the SDK: compare the expectation with the configuration
   schema and semantic conventions the repo's tests cite, and with what the other
   SDK does, and state which evidence you used.

### 2. Fresh full run

```bash
make test-all   # host; ~9 min; writes .junit-tbachert.xml and .junit-official.xml
```

Then parse:

```python
import xml.etree.ElementTree as ET
r = ET.parse('.junit-official.xml').getroot()
for tc in r.iter('testcase'):
    f = tc.find('failure') if tc.find('failure') is not None else tc.find('error')
    cls = tc.get('classname').rsplit('.', 1)[-1]   # dot-separated!
    ...
```

Compute: total/pass/fail per suite, per-class counts, and the list of failing
tests with their first assertion message (needed to map failures to root-cause
categories). Cross-check totals against the previous run before touching docs —
a delta you cannot explain is a flake or a regression, not a doc update.

### 3. Update `sdk-support-overview.md`

Structure (in order): header → Context propagation → Traces → Metrics → Logs →
Resource & entities → Exporters & transport → SDK-level & cross-cutting →
Footnotes → Upstream tracking table → Caveats → Deliberate deviation pins.

**Matrix accounting is machine-checked — do not hand-tally cells.** When a spec
test is added, removed or renamed:

1. Update `matrix-map.json` (row label → exact `Class::test` list). Every spec
   test must appear in exactly one row; the checker enforces this against the
   JUnit log.
2. Run `python3 check-matrix.py`. It re-derives each test's env/config mode from
   the group attributes, recomputes all four cells of every row (numerator AND
   denominator) from the JUnit results, and prints any cell that disagrees with
   the markdown. Fix the markdown until it prints OK.
3. Regenerate the collapsed test lists: `python3 check-matrix.py --update-details`
   (idempotent; replaces all `<details>` blocks in place).

The checker's printed column totals are the only totals: they must be identical
for both SDKs, because both runs count the same `spec` tests. Re-derive them from
the checker rather than trusting any number, including any number written in this
skill. The split is NOT "env-mode classes vs config-file classes": rows group by
behavior and some tests sit in a row other than their class's obvious one (see
Pitfalls).

The header carries the full-run counts for both SDKs, the legend, and the pointer
to the machine-checked accounting. It carries **no version lists and no
dependency notes**: versions are pinned in `sdks/official/composer.lock` and
`sdks/tbachert/composer.lock`, and the suite tracks the newest released versions,
so any version number written into the docs goes stale. Describe the
configuration data model in the README's per-SDK status text instead, phrased as
what the configuration package accepts and rejects as observed in the current run
(which `file_format` versions fail, which are accepted with a warning) rather than
as a pinned release or a specific upstream PR state.

Rules:

- **Scope = the shared `spec` group only.** Vendor-specific tests are documented
  in the README, never in the matrix.
- **Rows = tested behaviors** (behavior labels, no class names). Columns =
  SDK × config mode: tbachert env, tbachert config, official env, official
  config.
- Cell values: `✅ n/n`, partial `⚠️ k/n`, failing `❌ n/n` or `❌ 0/n`,
  not-applicable `–`. **Every non-green cell gets a footnote superscript.**
- Sections follow the spec structure; propagation is its own section first.
- **Parity principle:** every env-based behavior row has a config-file
  counterpart. One-sided rows are only legitimate for config-only data-model
  nodes or env-only parsing semantics.
- Footnotes: one consolidated block below all tables, numbered in order of first
  appearance. **End each footnote's last line with two trailing spaces** (GitHub
  hard break).
- Tracking table: official failures grouped by root cause; the **total row must
  equal the official suite's failure count**. Entries carry PR/issue links with a
  state annotation — "(draft)" / "(open)" / merged — or "untracked" or "not
  reported upstream (by decision)". Re-derive each row's existence and state from
  the fresh JUnit log plus a current upstream lookup; do not carry rows over from
  a previous run.
- Tests that assert on stderr output can be affected by incidental configuration
  warnings; re-derive which ones from the current JUnit log instead of keeping a
  list of names.

### 4. Update `README.md` (only what changed)

- SDK-independent framing; **no run-state details** (those live in the overview).
- The group list must match actual annotations: derive it from the test files
  (`grep -rhoE "Group\('[^']+'\)" tests/ | sed -E "s/Group\('(.*)'\)/\1/" | sort -u`;
  a pattern anchored on `#[Group(` misses groups declared as a second attribute on
  the same line, e.g. `#[Group('env'), Group('propagation')]`).
- Per-SDK sections state status + vendor-specific surface.

### 5. Consistency checks (all must pass)

1. `python3 check-matrix.py` prints OK (map completeness, every cell's
   numerator+denominator vs JUnit, column totals).
2. Tracking-table total == official failure count from the fresh run; every
   failing tbachert test is footnoted with its cause and visible in the header
   counts.
3. No stale numbers: `grep -n` the old totals and counts in both files and
   confirm each was updated or is genuinely unchanged.
4. Every non-green cell has a superscript; every footnote number is referenced;
   every footnote's stated test count equals the failures its cells imply.
5. Footnote paragraphs end with two trailing spaces on their last line.
6. README group list == actual annotations.
7. `git diff --stat` shows only the files the trigger intended to change, and the
   documentation work added no files to the repository. `README.md` and
   `sdk-support-overview.md` are the whole documentation surface: write scratch
   analysis outside the repository, or add a documentation file only when asked.
8. Both docs and this skill reference only repository-relative paths.

### 6. Commit

Commit message: no `#NNN` and no `github.com` references (project rule). A
dependency update and the docs regenerated for its test run are one logical unit:
commit them together. Rewrites of history must preserve author and committer
dates.

## Pitfalls

- **Never hand-tally matrix cells.** The committed matrix once looked consistent
  (column totals summed correctly) while six individual cells misallocated their
  tests (Views attribute/aggregation/matching, cross-signal config, end-to-end
  config denominator, precedence). Only the map plus the checker catches that.
- **Row-membership gotchas** (encoded in `matrix-map.json`, easy to break when
  editing):
  - `EnvEndToEndTest::testTraceContextPropagatesBetweenEnvAndConfigFileModes`
    is **config-file mode** (it runs a config-file service), so the e2e row has
    env=1 / config=2, not env=2.
  - The TLS row says "both protocols" and includes the three gRPC mTLS tests from
    `GrpcTest` (env mode); the gRPC exporter row therefore has 12 env tests, not
    15.
  - `OtlpExporterTest`'s two `*_EXPORTER`-selection tests belong to the SDK
    enablement row, not the OTLP HTTP options row (24 + 2 = 26).
  - `ConfigBasicTest` is spread over ten rows (OTLP HTTP, basics, span details,
    file exporter, console, SDK enablement, log level, id generation, e2e,
    precedence).
  - The Console row pulls tests from several env and config classes.
  - The two Views cardinality tests live in the "Cardinality limits" row, not in
    a Views row.
  Re-derive the current membership from `matrix-map.json`; the counts in these
  bullets are illustrations of the trap, not the current state.
- **Composite sampler classification:** `rule_based`, `probability`,
  `parent_threshold` and `always_record` are composite-sampler sub-samplers — one
  row covering all of them (`annotating` has no tests). When a row label lists
  some composite sub-samplers it must list all that are tested.
- **🟨 cells (async-only failures):** use 🟨 instead of ❌/⚠️ only when *every*
  failing test in the cell carries `#[Group('async')]` and no other failure reason
  applies (mark it with that cell's cause footnote plus the 🟨 explainer
  footnote, whose number comes from first-appearance order and therefore shifts
  between regenerations). A cell that fails for two reasons (async tests plus
  another cause) stays ❌. Derive the 🟨 set from the current JUnit log plus group
  attributes on every run; a previously listed 🟨 cell is not evidence that it
  still holds. `check-matrix.py` verifies cell counts and env/config membership
  only — it does not check the marker.
- **Attribute parsing must be comment-aware.** PHPUnit attributes may sit above a
  `/* */` or `//` comment that sits above the declaration. A line-based scan that
  treats comment lines as terminators silently drops those groups (this once
  undercounted the async set by one). `check-matrix.py::_groups_above` skips
  blank, attribute and comment lines; keep it that way.
- JUnit class sets differ between the two SDKs (`TbachertSpecificTest` only in
  tbachert, `OfficialSpecificTest` only in official) — exclude both when comparing
  spec-test sets.
- Timing-sensitive tests (periodic collection intervals vs async delays,
  Retry-After windows) flake under concurrent host load — never run two suites in
  parallel; a one-off extra failure that disappears on a clean re-run is a flake,
  not a doc change. Diff the two JUnit files to identify it.
- PHPUnit groups are **additive**; vendor-specific tests live in dedicated classes
  (`TbachertSpecificTest`, `OfficialSpecificTest`) so the `spec` group stays
  leak-free.
- The baseline is spec conformance, not "keep tbachert green" (see "Spec
  conformance outranks a green tbachert run"): spec-conform tests stay even when
  they fail on an SDK.
- Do not rename the project directory; the package name stays
  `tbachert/otel-integration-tests` (`Nevay/` is only the repository path).

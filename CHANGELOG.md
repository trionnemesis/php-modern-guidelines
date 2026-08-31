# Changelog

All notable changes will be documented in this file.

## [Unreleased]

### Changed

- Rule schema `1.1.0` changes `verification.phpcompatibility` from a nullable scalar to a sorted,
  unique list. All 16 bundled rules are migrated: nine carry the exact reviewed PHPCompatibility sniff
  ids and seven use an empty list for no proven mapping. A bidirectional consistency test keeps these
  rule-local lists exactly aligned with the adapter's sniff-to-rule map, including one-to-many and
  many-to-one relationships. This resolves issues
  [#11](https://github.com/trionnemesis/php-modern-guidelines/issues/11) and
  [#12](https://github.com/trionnemesis/php-modern-guidelines/issues/12).
- `verify phpcompatibility` now passes deterministic, explicit top-level scan operands and omits only
  the target root's exact `vendor/` directory. It deliberately does not use PHPCS's unanchored
  `--ignore` matching, so a checkout below a path component named `vendor` still scans its source. The
  effective operands remain verbatim in planned and executed invocation evidence, with a regression
  fixture proving both dependency exclusion and no false clean. This resolves issue
  [#14](https://github.com/trionnemesis/php-modern-guidelines/issues/14).

## [0.3.0] - 2026-08-31

### Added

- A real PHPCompatibility verification adapter for `verify phpcompatibility`: it runs a
  caller-selected, already-installed PHP_CodeSniffer with the PHPCompatibility standard as an isolated
  child process, projects the resolved policy onto the analyzer's version range exactly — refusing with
  exit `9` rather than approximating a coverage gap, an open upper bound, or a non-contiguous allowed
  set, with `--mode=single-target` as the documented escape hatch for a policy that would otherwise be
  refused — and normalizes findings with the external sniff id preserved verbatim, mapped internal rule
  ids only through a committed, reviewed mapping, and unmapped findings kept rather than discarded.
- `VerificationExecutor`, the single core-owned executor every adapter must route through to reach the
  process boundary (ADR-008's M3-B gate): it locates the executable itself on every call and binds the
  executed record to the process it actually ran, so an adapter cannot self-report a mismatched result.
  A complete 30-row outcome decision table — every combination of probe/analysis process state, from a
  missing executable through a timed-out or signaled child to a malformed JSON report — is proven through
  the real executor and a committed stub analyzer, with the target fixture tree verified byte-identical
  on every path.
- A committed sniff-to-rule mapping covering 9 of the repository's 16 rules: 13 sniff ids mapped to the
  eight rules found directly by the M3-B slice, plus — in a follow-up commit — the complete
  `extension.imap_unbundled` surface, 145 PHPCompatibility sniff ids spanning removed `imap_*`
  functions, removed `IMAP_*`/other constants, the removed `imap_connection` class, and a removed ini
  directive. This closes the gap the M3-B value gate found sharpest: `imap_open()` located precisely at
  file/line/column with no internal rule attached even though the catalogue already had one. Every other
  finding is preserved with `mapping_status: unmapped` rather than discarded.
- A `verification-analyzer` CI job that installs a pinned PHP_CodeSniffer + PHPCompatibility and runs the
  real-adapter and process-isolation test groups on the PHP 8.2 and 8.4 legs, enforced with
  `--fail-on-skipped --fail-on-empty-test-suite` so a namespace-restricted runner cannot pass by silently
  skipping the decision table it exists to prove.
- Documentation updates across the Agent Skill, the `AGENTS.md` snippet, both READMEs, and the Pages
  site describing `verify` as policy-aware, zero-mutation advisory evidence — never an automatic fix —
  covering the explicit `--executable` opt-in and its already-installed-tool prerequisite, the
  three-stage unavailable probe (missing executable, a program that is not PHP_CodeSniffer, a
  PHP_CodeSniffer without the PHPCompatibility standard registered), exit codes `6`–`9`, the
  `--mode=single-target` escape hatch, and current mapping coverage.

### Changed

- Fixed a defect present in the unreleased M3-A verification foundation and never previously exercised
  in production: `NativeProcessRunner` read its child process's namespace-status pipe *after*
  `proc_close()` had already closed it, so `isSupportedOnCurrentPlatform()` and every process launch
  raised `TypeError` instead of running anything. M3-B is the first slice with a production call site
  for the runner, which is why this had gone unnoticed; the fix is a two-site statement reorder with no
  other behavioral change.
- Fixed a defect that had silently disabled most of this repository's own test suite since M0, in CI and
  locally, on every prior release: one `ApplicationTester` in
  `tests/Integration/VersionCommandTest.php` was built without `setAutoExit(false)`, so Symfony's
  `Application::run()` called `exit(0)` from inside that test and PHPUnit reported success having
  actually run only 202 of 1140 tests. `tests/Unit/**` — including every M1–M3-A unit test and the
  ADR-008 boundary test — had therefore never run in CI at all before this release. The fix is three
  lines; the full 1140-test suite now runs to completion, and CI enforces it.
- Removed `Verification\Adapter\UnavailableAdapter` and its test. M3-B kept the M3-A placeholder
  registered nowhere but test coverage, as a retained cleanup candidate; with a real second adapter
  decision now made for `0.3.0`, the class had no production caller and no purpose beyond a closed test
  loop, so it and `UnavailableAdapterTest` are deleted together.

### Not included

- A PHPStan deprecation adapter (M3-C). Deferred: the M3-B value gate measured mapping coverage (9 of 16
  rules) and rule-catalogue depth (16 rules), not a missing analyzer, as the binding constraint on
  end-user value — adding a second analyzer would multiply unmapped findings against the same narrow
  catalogue rather than strengthen it. See
  [issue #9](https://github.com/trionnemesis/php-modern-guidelines/issues/9).
- A Rector dry-run advisory adapter (M3-D). Dropped rather than broadening the product boundary: Rector
  would require further platform expansion by definition (a diff-output normalizer and a
  config-materialization path for a tool that expects to write in the target), which issue #9's guidance
  treats as a reason to omit it from `0.3.0` rather than weaken the boundary. See
  [issue #9](https://github.com/trionnemesis/php-modern-guidelines/issues/9).
- Scoping `verify` away from `vendor/` findings. Measured at 94% of findings on a real project with
  dependencies installed, with no field in the report to filter on yet. Parked as
  [issue #14](https://github.com/trionnemesis/php-modern-guidelines/issues/14).
- A dedicated verification field on the rule schema, so sniff-to-rule mapping coverage becomes
  reviewable per rule in `list-rules` instead of living only as a private adapter constant. Parked as
  [issue #12](https://github.com/trionnemesis/php-modern-guidelines/issues/12).
- Source-backed rule-catalogue expansion. The value gate's own numbers argue for prioritizing this over
  further adapter work, so it is promoted on the [Roadmap](README.md#roadmap) ahead of M3-C rather than
  delivered in this release.
- Segmenting a non-contiguous allowed-minor set into multiple analysis invocations whose union equals the
  resolved policy. Such a policy is refused with a truthful exit `9` instead; no concrete need for
  segmentation has been demonstrated.
- A PHP 8.5 leg for the `verification-analyzer` CI job. The pinned PHPCompatibility analyzer has not
  measured or claimed PHP 8.5 support; the analyzer-free `checks` matrix still covers PHP 8.5.

## [0.2.0] - 2026-08-30

### Added

- Reproducible PHAR packaging: a committed `box.json.dist`, a CI job that builds the archive on the PHP
  8.2 floor and smoke-tests it against a project fixture, and a release step that attaches
  `php-modern-guidelines.phar` and `php-modern-guidelines.phar.sha256` to the guarded GitHub release.
- CI proof that the build is content-deterministic (two builds in one job, compared by sorted archive
  entries and a SHA-256 hash per entry) and that the archive's `resolve --json` output is byte-identical
  to the source checkout's for the same fixture.
- A distributable Claude Agent Skill in `skills/php-modern-guidelines/` and a Codex-compatible
  `AGENTS.md` snippet in `skills/agents-md/`, teaching an agent to resolve the project policy and query
  the rules before writing PHP, and to keep the feature and lifecycle ceilings separate.
- Tests that keep the agent-facing text truthful: every command, option, exit code and rule id the skill
  names must exist in the real CLI, every documented example is executed and compared byte-for-byte, and
  a command or option that exists but is undocumented fails the suite too.
- `doctor`: a read-only diagnostic of this tool's own inputs and installation — the running build, the
  project root, `composer.json` / `composer.lock` readability and JSON validity, the declared PHP values,
  the resolved policy summary, both bundled schemas, and the rules directory and its load — in human and
  JSON form.
- ADR-007, recording the PHAR build tool, its CI-only placement, the reproducibility definition, and the
  measured reason for rejecting a Composer dev dependency.

### Changed

- `doctor` was listed as "not included" in 0.1.0 and is now implemented. The 0.1.0 reasoning — that
  `resolve` already reports every readable input and every failure mode — held for a repository-local
  tool. Shipping a PHAR and third-party agent instructions added two questions `resolve` structurally
  cannot answer: whether a distribution's bundled rules and schemas are intact, and why the tool declined
  to answer, given that `resolve` fails closed with byte-empty stdout.
- `doctor` is the only command that prints its complete report when it exits non-zero, because the report
  is the diagnosis. It never prints a partial document, and a mistake in `doctor`'s own options is still
  rejected with byte-empty stdout like every other command. No new exit code was introduced.
- The build tool is pinned to an exact version and installed in CI only; `composer.json` gained no new
  `require` or `require-dev` entry, so `composer check` stays green without any PHAR toolchain.

### Not included

- PHPCompatibility, PHPStan-deprecation and Rector target-project adapters (M3), and framework rule packs
  (M4).
- New PHP rules or PHP lifecycle facts: the sixteen seed rules are unchanged.
- Agent marketplace manifests, plugin manifests, and any agent-runtime registration beyond the skill files
  and the `AGENTS.md` snippet.
- Auto-fixes, target-project writes, project-local configuration files, and network-based rule fetching.
- Byte-identical PHAR reproducibility: a PHAR embeds per-entry modification times and an archive
  signature, so ADR-007 claims and tests content-determinism instead.
- Packagist publication. The package is not registered on Packagist, so there is no
  `composer require` install path in `0.2.0`. The documented install paths are a git checkout and the
  released PHAR verified with its `.sha256` file.
- Pinned dependency resolution for the PHAR build. No `composer.lock` is committed, so each build resolves
  `symfony/console`, `opis/json-schema` and `composer/semver` fresh within the ranges `composer.json`
  declares. Content-determinism is proven within a single build job, not across jobs or across releases.

## [0.1.0] - 2026-08-30

### Added

- Composer Semver PHP compatibility resolver reading `composer.json` `require.php` and `conflict.php`,
  `config.platform.php`, `composer.lock` platform overrides, and an explicit `--php` override.
- `conflict.php` support: a PHP minor is removed from the allowed range when the conflict constraint
  covers the whole minor (patch-level conflicts do not remove a minor); reported via the
  `policy.conflict_php_applied` warning.
- Two-axis policy output (`feature_ceiling` / `lifecycle_ceiling`) with `coverage`, `confidence`,
  `sources` and `warnings`, in `range-safe`, `single-target` and `runtime-observed` modes; the JSON form
  is an instance of `schemas/policy.schema.json`.
- Schema-validating, deterministically ordered rule registry and a pure two-axis applicability engine.
- `resolve`, `list-rules` and `explain` commands with human-readable and machine-readable output.
- Sixteen source-backed PHP 8.2–8.5 seed rules under `resources/rules/`, covering features,
  modern preferences, deprecations, removals, a behavior change and a compatibility guard.
- Documented exit codes: 0 success, 2 invalid input, 3 unknown rule, 4 unresolvable policy,
  5 invalid rule data.

### Changed

- The rule-listing command is registered as `list-rules` (alias `rules`) because Symfony Console
  reserves `list` for its own command index. This is a deliberate deviation from issue #3's
  `php-modern-guidelines list` surface; Symfony's built-in `list` is neither removed nor overridden.
- Commands are still registered with `Application::add()`, which symfony/console 7.4 deprecates in
  favour of `addCommand()`. `addCommand()` does not exist in symfony/console 6.4, which `composer.json`
  still supports, so the deprecation is accepted until the Symfony floor is raised.

### Not included

- `doctor` (deferred to M2: `resolve` already reports every readable input, every unresolved value and
  every failure mode, and richer diagnostics would require inspecting or executing the target project,
  which ADR-006 forbids).
- Project-local configuration files, framework packs, PHPCompatibility / PHPStan-deprecation / Rector
  adapters, auto-fixes, PHAR packaging, agent marketplace manifests, and network-based rule fetching.

## [0.0.1] - 2026-08-30

### Added

- M0 repository foundation and Apache-2.0 licensing.
- Independent-reimplementation attribution and non-affiliation notice.
- Symfony Console skeleton with `php-modern-guidelines version`.
- Rule and policy JSON Schema contracts, tests, PHPStan, coding-style checks, and CI matrix.
- Static GitHub Pages overview and initial architecture decision records.
- Public README and Pages project surface with explicit M0 capability boundaries, trust model, and M1 handoff roadmap.
- Guarded GitHub Release workflow that publishes only when a `main` commit message starts with `release:`.

### Not included

- Composer policy resolution, actual modernization rules, framework guidance, analyzers/adapters, auto-fixes, PHAR packaging, and Agent marketplace manifests.

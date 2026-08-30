# Changelog

All notable changes will be documented in this file.

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

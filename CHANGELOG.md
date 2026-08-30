# Changelog

All notable changes will be documented in this file.

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

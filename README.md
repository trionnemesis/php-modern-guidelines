# Modern PHP Guidelines

[![CI](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/ci.yml/badge.svg)](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/ci.yml)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4)](https://www.php.net/)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE)
[![M0 foundation](https://img.shields.io/badge/status-foundation-5b4b8a)](CHANGELOG.md)

> Help AI coding agents write version-aware modern PHP.

**Foundation / alpha.** [`GitHub Pages`](https://trionnemesis.github.io/php-modern-guidelines/) · [`Documentation`](docs/) · [`Changelog`](CHANGELOG.md)

Modern PHP Guidelines is an independent, read-only foundation for a future CLI that will translate a project's declared PHP compatibility range into small, source-backed guidance before an agent changes PHP code. M0 exposes only `version`; policy resolution and rule guidance are deliberately not implemented yet.

## Why

A Composer PHP constraint is a range, not necessarily one runtime. The planned policy keeps two distinct axes:

- **Feature ceiling:** the lowest allowed PHP minor, so generated syntax stays compatible.
- **Lifecycle ceiling:** the highest known allowed minor, so newer deprecations and removals are not ignored.

For example, the approved future behavior for `^8.2` is a feature ceiling of 8.2 and a lifecycle ceiling of the latest known allowed minor. This is a design contract for PR B, not an implemented resolver. See [ADR-004](docs/adr/ADR-004-two-axis-policy.md).

## Current scope

| Capability | M0 status | Notes |
| --- | --- | --- |
| `php-modern-guidelines version` | Available | Reports `0.0.1`. |
| Rule and policy JSON schemas | Available | Contracts only; no rule data ships. |
| Composer constraint resolution | Planned (PR B) | Will use Composer Semver; no ad-hoc parsing. |
| `resolve`, `list`, `explain`, `doctor` | Planned | Not registered in M0. |
| PHP modernization rules | Planned | No factual PHP rules are included in this release. |
| Framework packs, analyzers, adapters, auto-fixes | Out of scope for M0 | Kept out to preserve a narrow, reviewable foundation. |

## Quick start

Requires PHP 8.2+ and Composer.

```bash
composer install
php bin/php-modern-guidelines version
```

Expected output:

```text
php-modern-guidelines 0.0.1
```

## CLI

Only this project-specific command exists in M0:

```text
php-modern-guidelines version
```

The future core commands will be deterministic and read-only: they will not execute target-project PHP, load the analyzed project's `vendor/autoload.php`, run Composer scripts or plugins, write to the analyzed repository, or require network access. This is a stated trust boundary, not a claim that M0 already resolves projects. See [ADR-006](docs/adr/ADR-006-read-only-core.md).

## Roadmap

| Milestone | Version | Focus |
| --- | --- | --- |
| M0 Foundation | `v0.0.1` | Repository contracts, CLI skeleton, checks, static site. |
| M1 Core parity | `v0.1.0` | Resolver, rule registry, `list` / `explain`, verified PHP rules. |
| M2 Agent distribution | `v0.2.0` | Skill, PHAR and wrappers. |
| M3 Verification adapters | `v0.3.0` | Explicit opt-in advisory integrations. |

## Repository anatomy

| Path | Purpose |
| --- | --- |
| `src/` | Symfony Console application skeleton. |
| `schemas/` | Versioned rule and policy contracts. |
| `docs/adr/` | Foundation decisions and boundaries. |
| `tests/` | CLI and schema smoke tests. |
| `site/` | Dependency-free GitHub Pages overview. |

## Inspiration and attribution

This project is independently implemented and inspired by [JetBrains/go-modern-guidelines](https://github.com/JetBrains/go-modern-guidelines). The upstream project describes guidelines and a CLI for code agents; its repository is licensed under Apache-2.0 ([upstream license](https://github.com/JetBrains/go-modern-guidelines/blob/main/LICENSE)). No upstream source code has been copied into this repository.

JetBrains and GoLand are trademarks of their respective owners. This project is not affiliated with or endorsed by JetBrains.

This project is intentionally narrower than the adjacent [netresearch/php-modernization-skill](https://github.com/netresearch/php-modernization-skill): it is designed around versioned PHP language/Core/extension policy and rule lookup, not broad modernization orchestration, framework conventions, analyzers, or automatic fixes.

## Contributing and security

Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing changes, especially source provenance requirements. Report vulnerabilities through [SECURITY.md](SECURITY.md).

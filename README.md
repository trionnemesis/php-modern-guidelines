# Modern PHP Guidelines

[![CI](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/ci.yml/badge.svg)](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/ci.yml)
[![Deploy Pages](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/pages.yml/badge.svg)](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/pages.yml)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4)](https://www.php.net/)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE)
[![M0 foundation](https://img.shields.io/badge/status-M0%20foundation-5b4b8a)](CHANGELOG.md)

> Help AI coding agents write version-aware modern PHP without silently exceeding a project's declared compatibility range.

**M0 / alpha · v0.0.1.** [Open GitHub Pages](https://trionnemesis.github.io/php-modern-guidelines/) · [Roadmap](#roadmap) · [Architecture decisions](docs/adr/) · [Changelog](CHANGELOG.md)

Modern PHP Guidelines is an independent, read-only foundation for a PHP version-aware policy and rule-query CLI. The long-term goal is to let coding agents inspect a project's declared PHP compatibility range before generating code, then prefer modern APIs and syntax only when that guidance remains valid across the supported range.

M0 intentionally implements only the repository contracts, CLI skeleton, schemas, CI, and documentation site. Composer policy resolution and actual modernization rules begin in M1.

## Why

AI coding agents can produce PHP that is syntactically valid on the developer's current runtime but incompatible with the project's declared minimum version, or keep generating APIs and idioms that newer PHP releases have deprecated or replaced.

A Composer PHP requirement is also a **range**, not necessarily one runtime. This project therefore keeps two policy axes separate:

| Axis | Meaning | Planned use |
| --- | --- | --- |
| **Feature ceiling** | Lowest PHP minor that must remain supported | Prevent generated syntax or APIs from requiring a newer PHP version. |
| **Lifecycle ceiling** | Highest known PHP minor still inside the supported range | Surface deprecations and removals that matter on newer allowed runtimes. |

For example, the approved future behavior for `^8.2` is to keep PHP 8.2 as the feature ceiling while evaluating lifecycle guidance through the highest known allowed minor. This is an architectural contract for M1, not an implemented resolver. See [ADR-004](docs/adr/ADR-004-two-axis-policy.md).

## Current capability

| Slice | Available in M0 | Boundary |
| --- | --- | --- |
| CLI foundation | `php-modern-guidelines version` | No project inspection or policy resolution yet. |
| Versioned contracts | Rule and policy JSON Schemas | Schemas define the contract; no factual PHP rule set ships yet. |
| Verification | PHPUnit, PHPStan, PHP-CS-Fixer, PHP 8.2–8.5 CI matrix | Verifies this repository, not a target PHP project. |
| Architecture | Six ADRs covering independence, scope, packaging direction, two-axis policy, provenance, and read-only execution | Future work must preserve these contracts unless an ADR explicitly supersedes them. |
| GitHub Pages | Dependency-free static overview under `site/` | Documentation only; it does not run analysis in the browser. |

### Not implemented yet

- Composer constraint and `config.platform.php` resolution.
- `resolve`, `list`, `explain`, or `doctor` commands.
- PHP modernization rule data.
- Framework rule packs such as Laravel or Symfony.
- PHPCompatibility, PHPStan-deprecation, or Rector target-project adapters.
- Auto-fixing, target-project writes, or marketplace manifests.

## Intended policy flow

The M1+ architecture is deliberately small and deterministic:

```mermaid
flowchart LR
    C[composer.json / composer.lock] --> R[Compatibility resolver]
    P[config.platform.php / explicit override] --> R
    R --> F[Feature ceiling]
    R --> L[Lifecycle ceiling]
    F --> Q[Versioned rule registry]
    L --> Q
    Q --> A[Agent guidance: resolve / list / explain]
    S[Official PHP provenance] --> Q
```

The resolver and rule-query stages in this diagram are **roadmap targets**, not M0 implementation claims.

## Quick start

Requires PHP 8.2+ and Composer.

```bash
git clone https://github.com/trionnemesis/php-modern-guidelines.git
cd php-modern-guidelines
composer install
php bin/php-modern-guidelines version
```

Expected output:

```text
php-modern-guidelines 0.0.1
```

Run repository checks with:

```bash
composer check
```

## Trust boundary

The core is designed to advise rather than execute a target project. Future core commands must remain deterministic and read-only unless a later ADR explicitly changes the contract.

They must not:

- execute target-project PHP;
- load the analyzed project's `vendor/autoload.php`;
- run Composer scripts or plugins;
- require network access for core resolution;
- write to the analyzed repository.

See [ADR-006](docs/adr/ADR-006-read-only-core.md).

## Source provenance

PHP language, Core, and bundled-extension facts must be backed by authoritative PHP sources such as official migration guides, PHP RFCs, or php-src upgrading documentation and must record a review date. If a fact cannot be established, the rule should remain absent or explicitly uncertain rather than guessed.

## Roadmap

| Milestone | Version target | Focus | Handoff boundary |
| --- | --- | --- | --- |
| **M0 Foundation** | `v0.0.1` | Repository contracts, CLI skeleton, schemas, CI, static Pages site | Complete. Do not retrofit M1 behavior into the foundation contracts without review. |
| **M1 Core parity** | `v0.1.0` | Composer Semver resolver, two-axis policy, rule registry, `resolve` / `list` / `explain`, verified seed PHP rules | Next implementation phase. Keep framework packs and target analyzers out. |
| **M2 Agent distribution** | `v0.2.0` | Agent Skill, PHAR packaging direction, Codex/Claude-compatible wrappers | Depends on stable M1 CLI/JSON contracts. |
| **M3 Verification adapters** | `v0.3.0` | Explicit opt-in PHPCompatibility, PHPStan-deprecation, and Rector advisory integration | Must remain advisory/read-only by default. |
| **M4 Framework packs** | `v0.4.x` | Isolated framework-specific guidance, starting with a separately reviewable pack | Must not contaminate PHP Core rules. |

## Repository anatomy

| Path | Purpose |
| --- | --- |
| `src/` | Symfony Console application skeleton and future policy/query implementation. |
| `schemas/` | Versioned rule and policy contracts. |
| `docs/adr/` | Binding architecture decisions and trust boundaries. |
| `tests/` | CLI, schema, and static-page verification. |
| `site/` | Dependency-free GitHub Pages overview. |
| `.github/workflows/` | CI and Pages publication workflows. |

## Inspiration and attribution

Primary reference project: [JetBrains/go-modern-guidelines](https://github.com/JetBrains/go-modern-guidelines).

Modern PHP Guidelines is an **independent implementation** inspired by that project's version-aware guidance model. The upstream repository is Apache-2.0 licensed ([upstream license](https://github.com/JetBrains/go-modern-guidelines/blob/main/LICENSE)). No upstream source files were copied into this repository.

JetBrains and GoLand are trademarks of their respective owners. This project is not affiliated with or endorsed by JetBrains.

This project also intentionally stays narrower than [netresearch/php-modernization-skill](https://github.com/netresearch/php-modernization-skill): the planned core is a version-aware PHP policy and rule-query engine rather than a broad modernization orchestrator, framework convention guide, analyzer suite, or automatic fixer.

## Contributing and security

Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing changes, especially the source-provenance and milestone-boundary rules. Report vulnerabilities through [SECURITY.md](SECURITY.md).

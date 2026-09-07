# Modern PHP Guidelines

[![CI](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/ci.yml/badge.svg)](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/ci.yml)
[![Deploy Pages](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/pages.yml/badge.svg)](https://github.com/trionnemesis/php-modern-guidelines/actions/workflows/pages.yml)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4)](https://www.php.net/)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE)
[![M3 verification adapter](https://img.shields.io/badge/status-M3%20verification%20adapter-5b4b8a)](CHANGELOG.md)
[![Verify: PHPCompatibility advisory adapter](https://img.shields.io/badge/verify-PHPCompatibility%20advisory%20adapter-0e7490)](docs/adr/ADR-008-external-verification-adapters.md)

> Give AI coding agents the project's real PHP version range, deprecated APIs, and modern alternatives before they generate code—avoiding code that runs locally but violates the project's minimum PHP version.

🌐 **[GitHub Pages overview](https://trionnemesis.github.io/php-modern-guidelines/)** ・ **繁體中文說明請見 [README.zh-TW.md](README.zh-TW.md)** ・ [Quick start](#quick-start) ・ [Current capabilities](#current-capabilities) ・ [Agent distribution](#agent-distribution) ・ [Policy flow](#policy-flow) ・ [Trust boundary](#trust-boundary) ・ [Roadmap](#roadmap) ・ [Changelog](CHANGELOG.md)

**Released: Rule-catalogue expansion · v0.3.9.** Modern PHP Guidelines is a standalone, read-only, version-aware PHP policy and rule-query CLI. It uses Composer Semver to resolve a target project's declared PHP compatibility range, separates “how new a syntax or API may be” from “how new a deprecation or removal must be considered,” and lets AI agents query source-backed PHP rules through `resolve`, `list-rules`, `explain`, and `doctor`. It now also ships as a Claude Agent Skill, a Codex-compatible `AGENTS.md` snippet, a CI-built, checksum-verified PHAR release asset, and an explicit, policy-aware `verify` surface backed by a real PHPCompatibility adapter.

> **Verification:** `v0.3.0` introduced the explicit, opt-in `verify <adapter> --executable=<path-or-name>` surface. Its production `phpcompatibility` adapter is a real PHPCompatibility implementation: it runs a caller-selected, already-installed PHP_CodeSniffer with the PHPCompatibility standard as an isolated child process and reports advisory evidence — never an automatic fix. A PHPStan deprecation adapter (M3-C) was deferred and a Rector dry-run adapter (M3-D) was dropped from this release line; see [Changelog](CHANGELOG.md) for why.

> **v0.3.1 hardening:** rule schema `1.1.0` stores PHPCompatibility mappings as sorted lists,
> and verification scans explicit top-level operands while omitting the exact project-root `vendor/`
> directory. The operands are recorded in the report; no unanchored PHPCS ignore pattern is used.

> **v0.3.2 rule-catalogue expansion:** eight new source-backed rules (16 → 24) covering PHP 8.2–8.5
> deprecations and features, plus twelve newly proven PHPCompatibility sniff mappings (nine of sixteen
> → sixteen of twenty-four rules mapped). No new adapter, no new PHP version coverage, and mapping
> coverage remains partial by design: `core.partially_supported_callables` ships with an empty mapping
> because PHPCompatibility reports no finding for it, and that is stated plainly rather than guessed at.

> **v0.3.3 rule-catalogue expansion:** eight more source-backed rules (24 → 32) covering PHP 8.2–8.5
> deprecations and features, plus twenty-five newly proven PHPCompatibility sniff mappings (sixteen of
> twenty-four → twenty-four of thirty-two rules mapped). No new adapter, no new PHP version coverage, and
> unlike the previous round's one unmapped rule, every one of these eight ships mapped — each was chosen
> from candidates whose PHPCompatibility mapping was already measured. Mapping coverage remains partial
> overall.

> **v0.3.4 rule-catalogue expansion:** eight more source-backed rules (32 → 40), all drawn from
> [issue #18](https://github.com/trionnemesis/php-modern-guidelines/issues/18)'s Tier B — candidates the
> CI-pinned PHPCompatibility analyzer produces no finding for at all. Unlike the previous two rounds,
> every one of these eight ships unmapped, so mapping coverage **falls** from twenty-four of thirty-two
> rules to twenty-four of forty. That is the deliberate trade this round makes, not a shortfall to spin
> away: catalogue depth is what the M3-B value gate measured as the binding constraint, and the
> highest-damage item in the whole register — the PHP 8.4 resource-to-object change, where an unchanged
> `is_resource()` guard silently takes the error branch on success — can never be mappable at all.

> **v0.3.5 rule-catalogue expansion:** eight more source-backed rules (40 → 48), all drawn from the
> [issue #18](https://github.com/trionnemesis/php-modern-guidelines/issues/18) Tier A candidates left
> unshipped after `v0.3.3` — the tier whose PHPCompatibility mapping was already measured. The dial
> reverses again: every one of these eight ships mapped, so mapping coverage **rises** from twenty-four
> of forty rules (60%) to thirty-two of forty-eight (67%). That emptied every Tier A candidate the
> analyzer-probing method had found — every one with a measured mapping became a shipped rule — leaving,
> at the time, two low-frequency Tier B candidates and two structural findings about the analyzer itself,
> in a still-partial catalogue with sixteen of forty-eight rules carrying no mapping at all. Declaring
> Tier A "exhausted" assumed analyzer-probing was a complete way to enumerate it; `v0.3.6` below found
> that assumption false.
>
> **v0.3.6 rule-catalogue expansion:** eight more source-backed rules (48 → 56). Rounds 1–4 chose
> candidates by probing PHPCompatibility, which bounded issue #18's register to whatever the analyzer
> already flagged. A [2026-09-05 re-measurement](https://github.com/trionnemesis/php-modern-guidelines/issues/18#issuecomment-5551728801)
> enumerated candidates from php-src `UPGRADING` first instead, and found **13 uncovered Core/Standard
> deprecations in 8.2–8.5, three of them mappable** — disproving the "Tier A exhausted" claim above. This
> round takes eight of those thirteen: three ship mapped (`core.stream_context_set_option_arity`,
> `core.csv_escape_parameter`, `core.socket_set_timeout`) and five ship unmapped
> (`core.http_response_header`, `core.output_in_output_handler`, `core.chr_ord_byte_range`,
> `core.directory_functions_implicit_handle`, `language.case_terminating_semicolon` — the last with no
> sniff in the pinned analyzer at all). Mapping coverage **falls** from thirty-two of forty-eight rules
> (67%) to thirty-five of fifty-six (62%), stated in the direction it moved rather than rounded away.
> These eight rules cover nine of the thirteen entries, one rule carrying both the `chr()` and the `ord()`
> entry, so four newly-found gaps — all unmappable — remain open, and twenty-one of the fifty-six
> rules now carry no mapping at all.
>
> **v0.3.7 rule-catalogue expansion:** eight more source-backed rules (56 → 64), and the first round to
> draw candidates from `UPGRADING`'s **Backward Incompatible Changes** section instead of its Deprecated
> Functionality section — behavior that silently changed rather than an API marked deprecated. Probing
> eighteen candidates from that section against the pinned analyzer produced **exactly one finding**,
> and that is structural rather than sampling noise: PHPCompatibility detects whether a symbol exists in
> a version range, and is blind to a function that still exists and now returns something different —
> `(int) 1.0e30` yields `5076964154930102272` today with no diagnostic at all, and only a rule can tell
> an agent not to write it. So this round ships almost entirely unmapped: seven of the eight new rules
> carry no PHPCompatibility mapping, and only `core.class_alias_reserved_names` does (one sniff id).
> Mapping coverage **falls** from thirty-five of fifty-six rules (62.5%) to thirty-six of sixty-four
> (56%) — the third deliberate breadth-for-depth trade, after `v0.3.4` and `v0.3.6`, and the steepest of
> the three. Measured directly against php-src `UPGRADING` rather than through the analyzer: of the 127
> Backward Incompatible Changes entries recorded across PHP 8.2–8.5, 36 are Core/Standard — the surface
> an agent writing ordinary application PHP can trip, the other 91 being extension-scoped — and the
> catalogue covered 2 of those 36 before this round. It now covers 11, because the eight new rules cover
> nine entries (`core.unrepresentable_numeric_casts` carries two: the float-to-int cast and the `NAN`
> cast); 25 remain uncovered. Twenty-eight of the catalogue's sixty-four rules now carry no mapping at
> all.
>
> **v0.3.8 rule-catalogue expansion:** eight more source-backed rules (64 → 72), and the **second**
> round drawn from `UPGRADING`'s Backward Incompatible Changes section — the section `v0.3.7` opened.
> Probing sixteen more candidates from it against the pinned analyzer produced, again, **exactly one
> finding**: two independent samples (eighteen candidates last round, sixteen this round) landing on the
> identical ratio confirms this is a structural property of the analyzer, not sampling noise —
> PHPCompatibility is blind by construction to a function that still exists and now behaves differently,
> so coverage will keep falling as the catalogue works through this section, and that is the correct
> outcome rather than a regression. Seven of the eight new rules ship unmapped; only
> `core.disable_classes_ini` (the round's one `removed`-kind rule) does, with one sniff id. Mapping
> coverage **falls** again, from thirty-six of sixty-four rules (56%) to thirty-seven of seventy-two
> (51%) — the fourth deliberate breadth-for-depth trade, after `v0.3.4`, `v0.3.6`, and `v0.3.7`, and the
> deepest yet. Two measured findings are worth stating here: `$object == true` and `$object == $variable`
> can disagree for the identical object and value — a class constant agrees with `(bool) $object`, a
> global `const` or `define()` does not — and `sprintf('%.f', 1.5)` is `'1.500000'` today and `'2'` on PHP
> 8.5, with no diagnostic on either side. Measured directly against php-src: of the 36 Core/Standard
> Backward Incompatible Changes entries in PHP 8.2–8.5, the catalogue covered 11 before this round and
> now covers 19, because the eight new rules cover eight entries — one each, unlike last round's one rule
> covering two — leaving 17 uncovered. Thirty-five of the catalogue's seventy-two rules now carry no
> mapping at all.
>
> **v0.3.9 rule-catalogue expansion:** eight more source-backed rules (72 → 80), and the **first** round
> drawn from `UPGRADING`'s **New Functions** sections rather than the Deprecated Functionality or Backward
> Incompatible Changes sections `v0.3.6` through `v0.3.8` worked — those ask whether a symbol still exists
> or now behaves differently, exactly where PHPCompatibility is structurally blind; New Functions asks
> exactly the question the analyzer was built to answer. Mapping coverage **rises** for the first time
> since `v0.3.5`, ending three consecutive falls (`v0.3.6`, `v0.3.7`, `v0.3.8`): from thirty-seven of
> seventy-two rules (51.4%) to forty-five of eighty (56.25%), landing back at exactly the `v0.3.7` level
> (thirty-six of sixty-four was also 56.25%). All eight new rules ship mapped and `SNIFF_RULE_MAP` gains
> fifteen sniff ids — [issue #18](https://github.com/trionnemesis/php-modern-guidelines/issues/18)
> predicted this direction, and this round confirms the prediction held rather than reporting a surprise.
> The seam is overwhelmingly extension-scoped: of the 54 New Functions entries this section still leaves
> open across Core/Standard and every named extension, only 3 (`fpow()`, `get_error_handler()`,
> `get_exception_handler()`) are Core/Standard, so `category: extension` nearly doubles, seven to
> thirteen. A feature rule is gated on the project's *floor* rather than its ceiling — the inverse of
> every deprecation rule in the catalogue — and this round also records three **measured defects** in the
> pinned analyzer's own New Functions data: a category never seen before, since every earlier structural
> finding said the analyzer was *blind*, not *wrong*.

## Why

AI coding agents often generate the newest PHP style based on their current runtime, while real projects commonly support several PHP minors. If a project declares `require.php: ^8.2`, seeing PHP 8.5 on the development machine does not prove that PHP 8.5-only syntax is safe to use.

This project therefore splits the Composer PHP range into two policy axes:

| Axis | Meaning | How an agent should use it |
|---|---|---|
| **Feature ceiling** | The lowest PHP minor that must remain compatible | Prevent syntax or APIs that require a higher PHP version |
| **Lifecycle ceiling** | The highest known PHP minor still allowed by the range | Surface deprecations, removals, and behavior changes from newer runtimes |

For example, `require.php: ^8.2` currently resolves to `feature_ceiling: 8.2` and `lifecycle_ceiling: 8.5`. If the constraint also allows versions after PHP 8.5, the tool reports a `coverage_gap` instead of inventing behavior for unknown future versions. See [ADR-004](docs/adr/ADR-004-two-axis-policy.md) for the complete contract.

## Current capabilities

| Slice | Implemented | Key boundary |
|---|---|---|
| Policy resolver | Resolves `require.php`, `conflict.php`, `config.platform.php`, Composer lock platform overrides, and `--php` | Reads target-project inputs without executing the target project |
| Two-axis policy | Separates `feature_ceiling` and `lifecycle_ceiling`; outputs `coverage`, `confidence`, and `warnings` | Known PHP coverage is 8.2–8.5 |
| Rule registry | Schema validation, deterministic ordering, and 80 source-backed PHP 8.2–8.5 rules | Currently covers PHP language, Core, and bundled extensions only |
| Agent query surface | `resolve`, `list-rules`, and `explain` with human and JSON output | `resolve --json` must satisfy `policy.schema.json` |
| CLI foundation | `version` and a consistent exit-code contract | Never writes to the target repository |
| Repository verification | PHPUnit, PHPStan level max, PHP-CS-Fixer, and PHP 8.2–8.5 CI | Verifies this repository; it does not scan the target project |
| Verification | `verify` command with a real, policy-aware PHPCompatibility adapter: canonical JSON schema, deterministic statuses, exact policy projection, and a committed sniff-to-rule mapping | Explicit opt-in, zero-mutation, advisory evidence only; PHPStan and Rector adapters are not included |
| Agent distribution | A Claude Agent Skill in `skills/php-modern-guidelines/` and a Codex-compatible `AGENTS.md` snippet in `skills/agents-md/` | Instructions only; no marketplace or plugin manifest, and no agent-runtime registration |
| PHAR distribution | A single-file archive built and smoke-tested in CI, attached to each release with a SHA-256 checksum | Built in CI only; the build tool is not a Composer dependency |
| Diagnostics | `doctor` reports what this tool found, read and loaded, in human and JSON form | Diagnoses this tool's inputs and installation; never inspects or executes the target project |

### Not implemented yet

- A project-local configuration file. `policy.schema.json` reserves `project.config`, but M2 does not read such a file.
- Laravel, Symfony, or other framework rule packs.
- PHPStan deprecation or Rector target-project adapters. The real PHPCompatibility adapter shipped in `v0.3.0`; PHPStan (M3-C) was deferred and Rector (M3-D) was dropped from this release line — see [Changelog](CHANGELOG.md).
- Auto-fixes, target-project writes, agent marketplace manifests, or network rule fetching.

`composer.json` `conflict.php` constraints are supported. A known PHP minor is removed only when the conflict covers that minor's complete interval; a patch-level conflict such as `8.3.5` does not remove all of PHP 8.3. An explicit override—`--php`, `config.platform.php`, a Composer lock platform override, or `runtime-observed` mode—directly determines the effective version and bypasses range inference from `require.php` and `conflict.php`.

## Agent distribution

M2 makes the M1 engine consumable by coding agents that do not vendor this repository: a distributable Claude Agent Skill, a plain-Markdown `AGENTS.md` wrapper for Codex-compatible agents, and a CI-built PHAR attached to each release.

Install the skill personally:

```bash
mkdir -p ~/.claude/skills
cp -R skills/php-modern-guidelines ~/.claude/skills/
```

Or inside a consuming project:

```bash
mkdir -p .claude/skills
cp -R skills/php-modern-guidelines .claude/skills/
```

For agents that read `AGENTS.md` by convention instead of a skill mechanism, paste the block from [`skills/agents-md/SNIPPET.md`](skills/agents-md/SNIPPET.md) into the consuming project's own `AGENTS.md`.

Install the released PHAR and verify it before running it:

```bash
curl -fsSL -o php-modern-guidelines.phar \
  https://github.com/trionnemesis/php-modern-guidelines/releases/latest/download/php-modern-guidelines.phar
curl -fsSL -o php-modern-guidelines.phar.sha256 \
  https://github.com/trionnemesis/php-modern-guidelines/releases/latest/download/php-modern-guidelines.phar.sha256
sha256sum -c php-modern-guidelines.phar.sha256
php php-modern-guidelines.phar version
```

The package is not published on Packagist yet, so there is no `composer require` install path; the [Quick start](#quick-start) git checkout below and this PHAR are the two supported installs.

The skill and `AGENTS.md` text are contract-tested against the real CLI, not review-tested: every command, option, exit code and rule id they name must exist in the real CLI, and every worked example is executed and compared byte-for-byte, so the instructions cannot silently drift from the tool.

The PHAR is built and smoke-tested in CI on the PHP 8.2 floor and published with a SHA-256 checksum; see [ADR-007](docs/adr/ADR-007-phar-build-and-distribution.md) for exactly what "reproducible" does and does not mean here—it is not a claim of byte-identical archives or pinned dependency versions across builds.

## Policy flow

The data flow stays small, deterministic, and read-only:

```mermaid
flowchart LR
    C[composer.json / composer.lock] --> R[Compatibility resolver]
    P[config.platform.php / explicit override] --> R
    R --> F[Feature ceiling]
    R --> L[Lifecycle ceiling]
    F --> Q[Versioned rule registry]
    L --> Q
    S[Official PHP provenance] --> Q
    Q --> A[Agent guidance]
    A --> X[resolve / list-rules / explain]
```

An agent should evaluate a project in this order:

1. Run `resolve` to determine the project's effective PHP policy.
2. Use `feature_ceiling` to restrict syntax and APIs that may be introduced.
3. Use `lifecycle_ceiling` to find relevant deprecations, removals, and behavior changes.
4. Filter applicable rules with `list-rules`, then use `explain` for the complete source-backed guidance.
5. Preserve coverage-gap or unknown-evidence warnings; do not fill missing PHP-version knowledge by assumption.

## Quick start

Requirements: PHP 8.2+ and Composer.

```bash
git clone https://github.com/trionnemesis/php-modern-guidelines.git
cd php-modern-guidelines
composer install
php bin/php-modern-guidelines version
```

Expected output:

```text
php-modern-guidelines 0.3.9
```

Resolve a target-project policy, list applicable rules, explain one rule, and diagnose the tool's own inputs:

```bash
php bin/php-modern-guidelines resolve --project-root=/path/to/app
php bin/php-modern-guidelines resolve --project-root=/path/to/app --json
php bin/php-modern-guidelines list-rules --project-root=/path/to/app --kind=deprecated
php bin/php-modern-guidelines explain language.property_hooks --project-root=/path/to/app
php bin/php-modern-guidelines doctor --project-root=/path/to/app
```

### Verifying with PHPCompatibility

Both the source checkout and the published `v0.3.9` PHAR expose the `verify` command. The explicit
shape is:

```bash
php bin/php-modern-guidelines verify phpcompatibility \
  --executable=/path/to/phpcs \
  --project-root=/path/to/app \
  --json
```

`verify` requires the caller to already have PHP_CodeSniffer installed with the PHPCompatibility
standard registered, and to select it explicitly with `--executable`; this tool never installs, updates,
or bundles an analyzer of its own. Before analysis it probes that executable in order — first that it
can be located, then that it reports a version, then that it has the PHPCompatibility standard
registered — so a missing executable, a program that is not PHP_CodeSniffer, and a PHP_CodeSniffer
installation without that standard are three distinct, truthful `unavailable` outcomes, all exit `7`.

Once the tool is available, `verify` projects the resolved policy — never the PHP version running this
CLI — onto the analyzer's version range exactly: one allowed minor becomes that minor, and a contiguous
range becomes its inclusive bounds. A policy the analyzer cannot express exactly (an open coverage gap or
a non-contiguous allowed set) is refused with exit `9` rather than approximated; pass
`--mode=single-target` to narrow the policy to one PHP minor first if you need a supported plan for such
a project. Completed runs exit `0` with no findings or `6` with one or more advisory findings; an
analyzer that fails mid-run exits `8`.

Every finding keeps the analyzer's own sniff identifier verbatim. Forty-five of this project's eighty
rules — including the whole `extension.imap_unbundled` surface — have a committed, reviewed mapping from
sniff id to rule id; every other finding is preserved with `mapping_status: unmapped` rather than discarded.
The same mappings are stored as sorted `verification.phpcompatibility` lists on the rule files and tested
as the exact inverse of the adapter map. Findings are advisory evidence to weigh, never an automatic fix:
`verify` only ever reads the target project through the selected external process, and tests prove the
target tree is byte-identical before and after every success and failure path.

Analysis uses explicit, sorted top-level operands and omits only the exact project-root `vendor/`
directory. Those operands are preserved in both planned and executed invocation evidence. The adapter
does not use PHPCS's unanchored `--ignore` matching, so a checkout whose ancestor path is itself named
`vendor` cannot be silently excluded.

PHPStan deprecation evidence (M3-C) is deferred, and Rector advisory evidence (M3-D) is dropped from this
release line rather than broadening the product boundary further — the M3-B value gate found that mapping
coverage and rule-catalogue depth, not a missing analyzer, are the binding constraint
([issue #9](https://github.com/trionnemesis/php-modern-guidelines/issues/9)). `v0.3.1` closes the bounded
follow-ups for explicit `vendor/` scoping and list-valued rule mappings tracked in
[#14](https://github.com/trionnemesis/php-modern-guidelines/issues/14) and
[#12](https://github.com/trionnemesis/php-modern-guidelines/issues/12). `v0.3.2` acts on that finding
directly: it adds eight source-backed rules (16 → 24) and twelve newly proven sniff mappings (nine of
sixteen → sixteen of twenty-four rules mapped), still with no new adapter and no new PHP version
coverage. `core.partially_supported_callables` is the one new rule that ships with no proven mapping,
because PHPCompatibility reports no finding for any of its deprecated callable shapes; every other
unmapped finding continues to be preserved rather than discarded. `v0.3.3` continues the same line of
work: eight more source-backed rules (24 → 32), every one of them drawn from the Tier A candidates
registered in [issue #18](https://github.com/trionnemesis/php-modern-guidelines/issues/18) — the tier
whose PHPCompatibility mapping was already measured, so all eight ship mapped — raising coverage from
sixteen of twenty-four rules to twenty-four of thirty-two, still with no new adapter and no new PHP
version coverage. `v0.3.4` deliberately takes the opposite tier: eight more source-backed rules
(32 → 40), all drawn from issue #18's Tier B — candidates the CI-pinned analyzer produces no finding for
at all — so every one of them ships unmapped and mapping coverage **falls** from twenty-four of
thirty-two rules to twenty-four of forty. That is the expected cost of trading mapping breadth for
catalogue depth, which the M3-B value gate measured as the binding constraint; it is stated plainly here
rather than spun as an improvement. The highest-damage item in the whole register, the PHP 8.4
resource-to-object change — where an unchanged `is_resource()` guard silently takes the error branch on
success instead of erroring loudly — is among the eight, and can never be mappable at this analyzer's
PHP 8.2 floor. `v0.3.5` reverses the dial again: eight more source-backed rules (40 → 48), all drawn
from the Tier A candidates issue #18 still had left after `v0.3.3` — the tier whose mapping was already
measured — so every one of them ships mapped and coverage **rises** from twenty-four of forty rules
(60%) to thirty-two of forty-eight (67%). That emptied every Tier A candidate the analyzer-probing
method had found, leaving, at the time, only two low-frequency Tier B candidates and two structural
findings about the analyzer itself in the register — though "exhausted" turned out to describe only
what analyzer-probing could see, not the full register; see `v0.3.6` below. One of the eight is worth a
specific note: `extension.mysqli_store_result_mode` triggers two PHPCompatibility sniffs that disagree
about when `MYSQLI_STORE_RESULT_COPY_DATA` was deprecated — the sniff table says PHP 8.1, but
`UPGRADING-8.1.0` only records the constant becoming a no-op there, and `UPGRADING-8.4.0` is the sole
official source that deprecates it. Per [ADR-005](docs/adr/ADR-005-official-source-provenance.md),
the analyzer loses that disagreement, so the rule states `deprecated_in: "8.4"` and leaves the
constant sniff deliberately unmapped rather than reconciled to match it. Mapping coverage is deeper
again, but still partial: sixteen of the catalogue's forty-eight rules carry no mapping at all.

`v0.3.6` corrects that assumption. Rounds 1 through 4 chose issue #18 candidates by probing the
CI-pinned analyzer, which bounds the register to whatever PHPCompatibility already flags; a
[2026-09-05 re-measurement](https://github.com/trionnemesis/php-modern-guidelines/issues/18#issuecomment-5551728801)
instead enumerated candidates directly from php-src `UPGRADING` and then probed each one for a mapping,
finding thirteen uncovered Core/Standard deprecations across PHP 8.2–8.5, three of them mappable. This
round takes eight of those thirteen: `core.stream_context_set_option_arity` (8.4, two sniff ids),
`core.csv_escape_parameter` (8.4, one sniff id) and `core.socket_set_timeout` (8.5, one sniff id) are
the three mappable candidates the re-measurement found, and all three ship mapped;
`core.http_response_header`, `core.output_in_output_handler`, `core.chr_ord_byte_range`,
`core.directory_functions_implicit_handle` and `language.case_terminating_semicolon` (8.5 each) ship
unmapped, and the last of those has no corresponding sniff in the pinned analyzer at all. Growing the
catalogue from forty-eight rules to fifty-six while adding only three new mappings **drops** coverage
from thirty-two of forty-eight rules (67%) to thirty-five of fifty-six (62%) — the same direction-first
honesty `v0.3.4` applied to its own decrease. Four of the thirteen newly-found gaps, all unmappable,
remain open. Measured directly against php-src rather than through the analyzer, twenty-two of the
thirty-five Core/Standard deprecation entries `UPGRADING` records for 8.2–8.5 were already in the
catalogue before this round; this round raises that to thirty-one of the thirty-five, though the
catalogue still does not mirror php-src's own register completely. Twenty-one of the catalogue's fifty-six rules now carry no mapping
at all.

`v0.3.7` draws on a different section of `UPGRADING` than any round before it: **Backward Incompatible
Changes** rather than **Deprecated Functionality** — behavior that silently changed rather than an API
marked deprecated. Probing eighteen candidates from that section against the CI-pinned analyzer produced
exactly one finding; that is structural, not sampling noise, since PHPCompatibility detects whether a
symbol exists in a version range and is blind to a function that still exists and now returns something
different. This round takes eight of those candidates: `core.str_split_empty_string` (8.2,
`str_split('')` now returns an empty array instead of `['']`), `core.range_argument_validation` (8.3,
`range()` now throws or warns on arguments it used to coerce silently), `core.negative_array_index_append`
(8.3, appending right after a negative array key now continues from `n + 1` instead of resetting to `0`),
`core.exit_as_function` (8.4, `exit`/`die` are now callable, `strict_types`-aware, and type-coercing),
`core.readonly_indirect_modification_clone` (8.4, taking a reference to a readonly property inside
`__clone()` now throws), `core.list_destructuring_non_array` (8.5, destructuring a non-array, non-`NULL`
value now warns), and `core.unrepresentable_numeric_casts` (8.5, casting an unrepresentable float or
`NAN` to `int` now warns — one rule covering two adjacent `UPGRADING` entries). Only the eighth,
`core.class_alias_reserved_names` (8.5, `"array"` and `"callable"` can no longer be used as
`class_alias()` names), ships mapped, with the single sniff id
`PHPCompatibility.Keywords.ForbiddenClassAlias.Found`; the other seven ship unmapped, each recording its
own zero-finding measurement rather than a guess. Worth stating plainly: `(int) 1.0e30` yields
`int(5076964154930102272)` today, on every PHP version this catalogue tracks, with no diagnostic of any
kind — no analyzer flags it, and only a rule can tell an agent not to write it. Growing the catalogue
from fifty-six rules to sixty-four while adding only one new mapping **drops** coverage from thirty-five
of fifty-six rules (62.5%) to thirty-six of sixty-four (56%) — the third deliberate breadth-for-depth
trade, after `v0.3.4` and `v0.3.6`, and the steepest of the three. Measured directly against php-src
`UPGRADING` rather than through the analyzer, and against a different, previously unmeasured section from
the Deprecated Functionality figures above: of the 127 Backward Incompatible Changes entries `UPGRADING`
records across PHP 8.2–8.5, 36 are Core/Standard — the surface an agent writing ordinary application PHP
can trip, the other 91 being extension-scoped — and the catalogue covered 2 of those 36 before this
round. It now covers 11, because the eight new rules cover nine of the 36 entries, not eight:
`core.unrepresentable_numeric_casts` alone carries two, the float-to-int cast and the `NAN` cast. 25 of
the 36 Core/Standard Backward Incompatible Changes entries remain uncovered, and twenty-eight of the
catalogue's sixty-four rules now carry no mapping at all.

`v0.3.8` is the **second** round drawn from this same `UPGRADING` section — Backward Incompatible
Changes rather than Deprecated Functionality — and it confirms rather than merely repeats last round's
finding. Probing sixteen more candidates from that section against the CI-pinned analyzer produced,
again, exactly one finding; two independent samples (eighteen candidates in `v0.3.7`, sixteen here)
landing on the identical one-finding ratio moves this from an observation to a property the catalogue can
plan around: PHPCompatibility answers whether a symbol exists in a version range and is blind by
construction to a function that still exists and now behaves differently, so as the catalogue keeps
working through this section, coverage will keep falling, and that is the correct outcome rather than a
regression (see [issue #18](https://github.com/trionnemesis/php-modern-guidelines/issues/18)). This round
takes eight candidates, each covering one `UPGRADING` entry rather than one rule spanning two as in
`v0.3.7`: `core.file_flags_validation` (8.3, `file()` now validates its `$flags` argument and throws
`ValueError` for a bit outside its accepted mask — `FILE_APPEND` is a real constant but belongs to
`file_put_contents()`, not `file()`, and now throws when passed here), `core.trait_static_property_redeclaration`
(8.3, a subclass that re-uses a trait its parent already uses now gets its own, separate static property
storage instead of silently sharing the parent's), `core.proc_get_status_repeated_calls` (8.3, a bug fix
rather than a regression: `proc_get_status()` now returns the correct state on every call rather than
only the first), `core.http_build_query_backed_enums` (8.4, `http_build_query()` now contributes a backed
enum's scalar `->value` directly and throws `ValueError` for a pure enum), `core.loose_object_boolean_comparison`
(8.5, detailed below), `core.attribute_target_validation` (8.5, marking an abstract class, enum,
interface, or trait with `#[\Attribute]` moves from a runtime `Error` on instantiation to a compile-time
error), `core.printf_empty_precision` (8.5, detailed below), and `core.disable_classes_ini` (8.5, `kind:
removed`, P0: the `disable_classes` php.ini directive is deleted outright, silently restoring
instantiability to whatever classes it used to block). Only the last of those, the round's one `removed`
rule, ships mapped, with the single sniff id
`PHPCompatibility.IniDirectives.RemovedIniDirectives.disable_classesRemoved`; the other seven ship
unmapped, each recording its own zero-finding measurement rather than a guess. Two of those seven are
worth stating plainly. `core.loose_object_boolean_comparison` measured that `$object == true` and
`$object == $variable` — the identical object, the identical boolean value — can disagree depending only
on the syntactic shape of the right-hand side: a bare literal and, unexpectedly, a scalar class constant
both agree with `(bool) $object`, while a variable, a global `const`, or a `define()` all return `false`
regardless, so `if ($enum == $flag)` can silently never fire and no analyzer sees it.
`core.printf_empty_precision` measured that `sprintf('%.f', 1.5)` is `'1.500000'` today and becomes `'2'`
on PHP 8.5, with no diagnostic on either version; the same empty-precision change means `'%.s'` will start
silently truncating strings to `''`. Growing the catalogue from sixty-four rules to seventy-two while
adding only one new mapping **drops** coverage from thirty-six of sixty-four rules (56%) to thirty-seven
of seventy-two (51%) — the fourth deliberate breadth-for-depth trade, after `v0.3.4`, `v0.3.6`, and
`v0.3.7`, and the deepest yet. Measured directly against php-src `UPGRADING` rather than through the
analyzer: of the 36 Core/Standard Backward Incompatible Changes entries recorded across PHP 8.2–8.5, the
catalogue covered 11 before this round. It now covers 19, because the eight new rules cover eight of the
36 entries — one rule per entry this time, unlike `v0.3.7` where one rule carried two — leaving 17 of the
36 entries uncovered. Thirty-five of the catalogue's seventy-two rules now carry no mapping at all.

`v0.3.9` is the **first** round drawn from `UPGRADING`'s **New Functions** sections rather than Deprecated
Functionality (`v0.3.2`–`v0.3.6`) or Backward Incompatible Changes (`v0.3.7`, `v0.3.8`): both of those ask
whether a symbol still exists in a version range or now behaves differently, exactly the question
PHPCompatibility is structurally blind to; New Functions asks exactly the question the analyzer was built
to answer — does this symbol exist here — so this round's direction was predicted in
[issue #18](https://github.com/trionnemesis/php-modern-guidelines/issues/18) rather than discovered by
surprise, and the measurement confirms the prediction held. This round takes eight candidates:
`core.get_error_exception_handler` (8.5, `get_error_handler()`/`get_exception_handler()` read the
installed error/exception handler without the pre-8.5 replace-then-restore workaround, whose window a
signal handler can genuinely desynchronize), `core.fpow` (8.4, an IEEE-754 `pow()` that is **not** a
drop-in: `fpow(3.0, 35.0)` measures `50031545098999704` against `pow(3, 35)`'s exact `50031545098999707`),
`extension.mb_str_pad` (8.3, mbstring's character-counting `str_pad()`, whose `$length` silently reverts
to byte-counting if `$encoding` is wrong), `extension.mb_trim_functions` (8.4, strips Unicode whitespace
plus form feed — not simply "`trim()` plus Unicode spaces" — with no `a..z` range expansion in
`$characters`), `extension.mb_case_first_functions` (8.4, `mb_ucfirst()` performs genuine Unicode
title-casing, U+01F3 → U+01F2, not the U+01F1 upper-casing `mb_strtoupper()` gives the same input),
`extension.bcmath_rounding_functions` (8.4, `bcfloor()`/`bcceil()`/`bcround()`/`bcdivmod()`; the first two
take exactly one argument, unlike `bcround()`), `extension.grapheme_str_split` (8.4, splits by grapheme
cluster rather than byte or codepoint — a ZWJ family emoji is 25 bytes, 7 codepoints, and measures as
exactly 1 grapheme), and `extension.curl_multi_get_handles` (8.5, enumerates every handle attached to a
multi handle, including ones a `CURLMOPT_PUSHFUNCTION` callback accepted that application-side bookkeeping
never explicitly added). All eight ship mapped, and `SNIFF_RULE_MAP` gains fifteen sniff ids (209 → 224).
Mapping coverage **rises** — the first rise since `v0.3.5`, ending three consecutive falls (`v0.3.6`,
`v0.3.7`, `v0.3.8`) — from thirty-seven of seventy-two rules (51.4%) to forty-five of eighty (56.25%),
landing back at exactly the `v0.3.7` level (thirty-six of sixty-four was also 56.25%).

The seam this round works is overwhelmingly extension-scoped, and that is the round's main structural
finding. Measured directly: `UPGRADING` 8.2–8.5 carries 101 New Functions bullet entries; the pinned sniff
knows 76 functions across that range; 11 of the 8.3+ ones were already named somewhere in the catalogue,
leaving 54 open — and only 3 of those 54 (`fpow()`, `get_error_handler()`, `get_exception_handler()`) are
Core/Standard. Everything else needs a named extension, which is why `category: extension` nearly
doubles, seven to thirteen, this round. A feature rule is also worth stating plainly as the inverse of
every deprecation rule in the catalogue: it is gated on the project's *floor* rather than its ceiling — it
answers "can I write this call yet?" rather than "has this been deprecated yet?", which changes how
`--php` gating reads for this batch — and the fixture proof inverts to match. For a `removed`/`deprecated`
sniff, raising the ceiling makes a finding appear; a `New*` sniff fires on the floor instead. Measured on
the fifteen mapped calls with the ceiling fixed at 8.5: 15 findings at `8.2-8.5`, 14 at `8.3-8.5`, 3 at
`8.4-8.5`, and 0 at `8.5-8.5` — a ladder that partitions the batch exactly by `introduced_in` (1 sniff id
at 8.3, 11 at 8.4, 3 at 8.5).

This round also measured three defects in the pinned analyzer's own New Functions data — a category this
project has not recorded before, since every earlier structural finding said the analyzer was *blind* to
something, never that its data disagreed with php-src. First, the sniff registers
`http_clear_last_response_header`/`http_get_last_response_header` (singular) as 8.4 additions, but
`UPGRADING-8.4`'s own text and a direct `function_exists()` check agree the real names are plural
(`http_get_last_response_headers()`/`http_clear_last_response_headers()`); the singular sniff ids fire on
code that calls a function that does not exist and stay silent on the real one, so no rule maps them —
which is why `core.http_get_last_response_headers` is not in this batch despite being an obvious New
Functions candidate (the catalogue already documents the functions' 8.4 availability in prose, inside
`core.http_response_header`, their deprecation counterpart). Second, the sniff claims
`openssl_password_hash()`/`openssl_password_verify()` as an 8.4 addition that no `UPGRADING` file for 8.1
through 8.5 mentions and that `function_exists()` measures `false` for, with `openssl` loaded. Third, the
sniff typo's `openssl_cipher_key_length()`'s extension attribution as `openssal`. The first defect matters
to this project specifically: `SNIFF_RULE_MAP` is kept an exact bidirectional inverse of each rule's
`verification.phpcompatibility` list, so transcribing that sniff id from the sniff source would have
produced a mapping that could never fire, silently — and it was caught only because this round's
discipline requires reproducing every id by running the analyzer over a probe file before writing it into
a rule, rather than transcribing it from the sniff's own table. Thirty-five of the catalogue's eighty
rules still carry no mapping at all — the same absolute count as before this round, since every one of the
eight new rules shipped mapped.

For a target project declaring `require.php: ^8.2`, representative `resolve` output is:

```text
PHP policy
  mode                 range-safe
  project root         <app>
  declared constraint  ^8.2
  allowed minors       8.2, 8.3, 8.4, 8.5
  feature ceiling      8.2
  lifecycle ceiling    8.5
  platform override    -
  observed runtime     -
  coverage             coverage_gap (known 8.2-8.5, open upper bound)
  confidence           declared

Sources
  composer.require.php  composer.json  ^8.2

Warnings
  coverage.open_upper_bound_bounded: The constraint "^8.2" allows PHP minors newer than 8.5, which this tool does not know. Lifecycle guidance stops at 8.5.
```

Verify this repository:

```bash
composer check
```

## CLI contract

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Success |
| `1` | Unexpected internal error; a bug in the tool rather than an input problem |
| `2` | Invalid input, such as malformed or unreadable Composer JSON, an unparseable constraint, an unknown option value, or a minor outside known coverage |
| `3` | The rule id given to `explain` does not exist |
| `4` | A valid PHP constraint contains none of the PHP minors known to this tool, so policy resolution cannot proceed |
| `5` | Invalid rule data, such as a malformed rule, duplicate id, or filename/id mismatch |
| `6` | Verification completed and produced one or more advisory findings |
| `7` | The selected verification adapter or executable is unavailable |
| `8` | The verification adapter could not complete execution |
| `9` | The resolved PHP policy cannot be projected exactly into the selected analyzer |

For any non-zero exit from `resolve`, `list-rules` or `explain`, human-readable output is written to stderr, and in `--json` mode stdout remains byte-empty so JSON consumers never receive a partial document. `doctor` is the one documented exception: its report *is* the diagnosis, so it prints the complete report on stdout and leaves stderr empty even when it exits non-zero—except for a mistake in `doctor`'s own options, which is rejected before any check runs and still prints nothing on stdout.

`verify` is the other report-producing surface. Outcomes `0`, `6`, `7`, `8`, and `9` write one complete
human or canonical JSON report to stdout and leave stderr byte-empty. Invalid invocation, policy,
rule-data, and internal errors keep the established `2`, `4`, `5`, and `1` empty-stdout semantics; no
JSON consumer receives a partial verification document.

### `list-rules`

`list-rules` implements the original plan's `list` query, but Symfony Console reserves `list` for its built-in command index. This project therefore uses `list-rules` and provides `rules` as an alias.

By default, it hides rules with `not_in_range` status. Use `--all` to show every rule. `--kind`, `--category`, `--priority`, and `--status` are repeatable and can be combined with `--extension` and `--minor`. `-r` and `-m` are shorthand for `--project-root` and `--mode`.

### `doctor`

`doctor` runs nine fixed, ordered, read-only checks over this tool's own inputs and installation for a target project: the running build (version, and whether it runs from a PHAR or from source), the project root, `composer.json` and `composer.lock` presence/readability/JSON validity, the declared PHP values, the resolved policy summary, the two core rule/policy schemas, and the effective rules directory and its load. Each check reports a status (`ok` / `warn` / `fail` / `skipped`), a fixed one-line summary and a fixed set of detail keys, in both human-readable and `--json` form; the JSON form carries `output_version` like `list-rules` and `explain`. It introduces no new exit code—the process exit code is whichever of `1` / `2` / `4` / `5` the first failing check would already produce today. As noted above, `doctor` prints its complete report on stdout even when it exits non-zero, because the report is the diagnosis; a mistake in `doctor`'s own options is the one case that still prints nothing.

### `verify`

`verify` takes one required adapter argument, the four shared policy options (`--project-root`, `--php`,
`--mode`, `--json`), and a required `--executable` path or `PATH` name. It resolves policy before asking
the selected adapter for evidence. The canonical JSON document satisfies
[`verification.schema.json`](schemas/verification.schema.json) and records status, exit code, adapter,
policy fingerprint and projection status, the prevalidated invocation plan, actual attempted invocations,
deterministic counts, reason, mapped
source-backed rule contexts, and mapped or unmapped external findings. It emits no timestamp.
Plans distinguish non-partitioning tool probes from policy-partitioned analysis, and record the fixed
`project_root` working-directory role, bounded timeout, capped output, and sanitized environment role.
Parent temporary-directory variables are ignored; all analyzer temp variables use one controlled,
canonical writable directory outside the target or execution fails closed. Machine-specific executable
prefixes are normalized out of report evidence. Native execution additionally requires an operational
Linux user/PID namespace, so descendants cannot escape cleanup by creating a new session or process group;
hosts that cannot provide it fail closed.

This is an explicit adapter boundary, not an arbitrary-command interface: the caller cannot supply raw
analyzer arguments. Production recognizes only `phpcompatibility`, a real PHPCompatibility
implementation. A PHPStan deprecation adapter and a Rector dry-run adapter are not included in this
release line ([Changelog](CHANGELOG.md)). Missing tools and unsupported policy projections remain
`unavailable` or refused; they are never installed, approximated, or presented as a successful scan. See
[ADR-008](docs/adr/ADR-008-external-verification-adapters.md).

## PHP coverage and fail-safe behavior

Known PHP minors currently span **8.2–8.5**. When a project constraint extends beyond this window, the tool does not fabricate knowledge.

| Situation | Behavior | Risk interpretation |
|---|---|---|
| Constraint allows a version below PHP 8.2 | Clamps `feature_ceiling` to the known floor of 8.2 and reports `coverage_gap` / `coverage.below_known_min` | Do not trust blindly; the real project may still support PHP 8.0 or 8.1 |
| Constraint allows a version above PHP 8.5 | Reports `coverage.open_upper_bound` and a corresponding warning | Existing generated code does not become unsafe, but later deprecations and removals are not covered |
| Explicit `--php` names an unknown minor | Fails closed with exit `4` | Never presents an unknown runtime as supported |

Coverage below the known floor deserves special attention. If the real project still supports PHP 8.0 or 8.1, this tool cannot prove that a PHP 8.2-only feature is safe. Treat this warning as a signal to extend rules and coverage, not as a warning to ignore.

## Rule model

Rules use three categories:

| Category | Scope |
|---|---|
| `language` | Parser-level syntax |
| `core` | Runtime-visible functions, classes, attributes, constants, and engine behavior |
| `extension` | Behavior requiring a named, non-default-bundled extension |

For `modern_preference` and `behavior_change`, `introduced_in` means the version in which the preferred API arrived or the behavior changed—not the version in which the legacy form was first introduced. `affected_minors` identifies the currently allowed minors for which the guidance applies; it is not a duplicate of the lifecycle-event version.

### `single-target` mode

The two-axis split is the default guarantee of `range-safe` mode. `--mode=single-target` is an explicit caller decision to narrow the range to one PHP minor. The allowed set then contains one minor, so `feature_ceiling` and `lifecycle_ceiling` are equal; the `mode.single_target_narrowed` warning discloses the narrowing.

### Rule JSON stability

The `rule` object from `explain --json` supports a deterministic round trip. Decoding it with the canonical encoder, rebuilding a `Rule`, and encoding it again preserves both the JSON value and output bytes. This guarantees data-contract stability, not PHP object identity.

## Trust boundary

The core provides verifiable guidance; it does not execute or modify a target project. Unless a future ADR explicitly changes this contract, core commands remain deterministic and read-only.

They must not:

- Execute target-project PHP.
- Load the target project's `vendor/autoload.php`.
- Run Composer scripts or plugins.
- Require network access for core resolution.
- Write to the analyzed repository.

See [ADR-006](docs/adr/ADR-006-read-only-core.md) for the complete design.

The `verify` surface is a separately explicit boundary governed by
[ADR-008](docs/adr/ADR-008-external-verification-adapters.md). Its production `phpcompatibility` adapter
runs as an isolated child process, consumes the resolved policy exactly, avoids
network/configuration/install behavior, retains unmapped evidence, and is tested to prove that the
target tree is byte-identical before and after every path. Any future real adapter must meet the same
bar. Verification does not weaken the metadata-only core commands.

## Source provenance

Lifecycle facts for PHP language, Core, and bundled extensions require an authoritative PHP source, such as:

- An official PHP migration guide.
- A PHP RFC.
- php-src `UPGRADING` documentation.

Every rule also stores its review date. If a fact cannot be established, the rule stays absent or records uncertainty explicitly; missing facts are never filled by guesswork.

## Roadmap

| Milestone | Version | Status / focus | Handoff boundary |
|---|---|---|---|
| **M0 Foundation** | `v0.0.1` | ✅ Complete: repository contracts, CLI skeleton, schemas, CI, and static Pages | Foundation contract established |
| **M1 Core parity** | `v0.1.0` | ✅ Complete: Composer Semver resolver, two-axis policy, rule registry, `resolve` / `list-rules` / `explain`, and 16 seed rules | Framework packs and target analyzers do not enter M1 |
| **M2 Agent distribution** | `v0.2.0` | ✅ Complete: Agent Skill, Codex/AGENTS.md wrapper, CI-built PHAR attached to releases, and bounded `doctor` | Depends on the stable M1 CLI and JSON contract |
| **M3 Verification adapters** | `v0.3.0` | ✅ Complete: a real PHPCompatibility adapter shipped as advisory evidence; PHPStan deprecation ([#9](https://github.com/trionnemesis/php-modern-guidelines/issues/9)) deferred and Rector dropped rather than broadening the product | Explicit opt-in, exact policy projection, advisory evidence, and zero target writes |
| **M3 patch hardening** | `v0.3.1` | ✅ Complete: rule-local ordered verification mappings and deterministic vendor-safe scan scoping | No new analyzer infrastructure; closes #11, #12, and #14 |
| **Rule-catalogue expansion** | `v0.3.2` | ✅ Complete: acted on the M3-B value gate finding that mapping coverage and the 16-rule catalogue, not a missing analyzer, were the binding constraint — added 8 source-backed rules (16 → 24) and 12 proven PHPCompatibility sniff mappings (9 of 16 → 16 of 24 rules mapped) | No new adapter infrastructure; mapping coverage is deeper but still partial |
| **Further catalogue and mapping growth** | `v0.3.3` | ✅ Complete: added 8 more source-backed rules (24 → 32) and 25 proven PHPCompatibility sniff mappings (16 of 24 → 24 of 32 rules mapped), every one of the eight new rules shipping mapped | No new adapter infrastructure; mapping coverage is deeper but still partial |
| **Catalogue depth over mapping breadth** | `v0.3.4` | ✅ Complete: added 8 more source-backed rules (32 → 40), all from issue #18's Tier B — candidates the analyzer produces no finding for at all — so every one ships unmapped and mapping coverage **falls** from 24 of 32 rules to 24 of 40 | No new adapter infrastructure; the drop is a deliberate, measured trade for catalogue depth, not a regression |
| **Emptying issue #18's Tier A** | `v0.3.5` | ✅ Complete: added the 8 remaining source-backed rules from issue #18's Tier A (40 → 48), every one shipping mapped, so mapping coverage **rises** from 24 of 40 rules (60%) to 32 of 48 (67%); Tier A, as bounded by analyzer-probed candidates, was believed exhausted — a `v0.3.6` re-measurement from php-src `UPGRADING` found this incomplete | No new adapter infrastructure; the register still holds two low-frequency Tier B candidates, two structural analyzer findings, and 16 of 48 rules with no mapping |
| **Re-measuring issue #18 from php-src** | `v0.3.6` | ✅ Complete: enumerating issue #18 candidates from php-src `UPGRADING` first, rather than by probing the analyzer, found 13 uncovered Core/Standard deprecations in 8.2–8.5 (3 mappable) and disproved `v0.3.5`'s "Tier A exhausted" claim; this round ships 8 rules covering 9 of the 13 entries (48 → 56 rules), so mapping coverage **falls** from 32 of 48 rules (67%) to 35 of 56 (62%) | No new adapter infrastructure; 4 of the 13 newly-found gaps remain open, all unmappable, and 21 of 56 rules carry no mapping |
| **Backward Incompatible Changes from php-src** | `v0.3.7` | ✅ Complete: the first round drawn from `UPGRADING`'s Backward Incompatible Changes section instead of Deprecated Functionality — behavior that silently changed rather than an API marked deprecated; probing 18 candidates against the analyzer produced exactly 1 finding, so 7 of the 8 new rules (56 → 64) ship unmapped and mapping coverage **falls** from 35 of 56 rules (62.5%) to 36 of 64 (56%), the steepest of the three deliberate breadth-for-depth trades (with `v0.3.4` and `v0.3.6`); measured directly against php-src, catalogue coverage of the 36 Core/Standard Backward Incompatible Changes entries in 8.2–8.5 rises from 2 to 11, because the eight rules cover 9 of the 36 entries | No new adapter infrastructure; 25 of the 36 Backward Incompatible Changes entries and the 4 Deprecated Functionality gaps `v0.3.6` left open remain uncovered, and 28 of 64 rules carry no mapping |
| **Second Backward Incompatible Changes round** | `v0.3.8` | ✅ Complete: the second round drawn from `UPGRADING`'s Backward Incompatible Changes section; probing 16 more candidates against the analyzer again produced exactly 1 finding, confirming the pattern `v0.3.7` first observed rather than sampling noise, so 7 of the 8 new rules (64 → 72) ship unmapped and mapping coverage **falls** from 36 of 64 rules (56%) to 37 of 72 (51%), the fourth deliberate breadth-for-depth trade (with `v0.3.4`, `v0.3.6`, `v0.3.7`) and the deepest yet; measured directly against php-src, catalogue coverage of the 36 Core/Standard Backward Incompatible Changes entries in 8.2–8.5 rises from 11 to 19, because the eight rules cover 8 of those entries, one each | No new adapter infrastructure; 17 of the 36 Backward Incompatible Changes entries and the 4 Deprecated Functionality gaps `v0.3.6` left open remain uncovered, and 35 of 72 rules carry no mapping |
| **New Functions from php-src** | `v0.3.9` | ✅ Complete: the first round drawn from `UPGRADING`'s New Functions sections rather than Deprecated Functionality or Backward Incompatible Changes — a section PHPCompatibility was built to answer rather than one it is structurally blind to, so all 8 new rules (72 → 80) ship mapped and mapping coverage **rises** for the first time since `v0.3.5`, from 37 of 72 rules (51.4%) to 45 of 80 (56.25%), ending three consecutive falls and landing back at the `v0.3.7` level; `SNIFF_RULE_MAP` gains 15 sniff ids (209 → 224); measured directly against php-src, of the 101 New Functions bullet entries in 8.2–8.5 the pinned sniff knows 76, 11 of the 8.3+ ones were already named in the catalogue, and only 3 of the 54 still open are Core/Standard, so `category: extension` nearly doubles, 7 to 13; also records three measured defects in the pinned analyzer's own data (a wrong function name, two nonexistent functions, one typo'd extension attribution) — the first findings calling the analyzer *wrong* rather than merely *blind* | No new adapter infrastructure; 35 of 80 rules carry no mapping, including the 4 Deprecated Functionality gaps and 17 Backward Incompatible Changes Core/Standard entries earlier rounds left open, plus 51 of the 54 open New Functions entries this round found — all extension-scoped and untouched |
| **Next: further catalogue and mapping growth** | — | Planned: mapping coverage still covers only 45 of 80 rules, so growing further source-backed PHP rules and their proven mappings — including the 4 Deprecated Functionality gaps, the 17 Backward Incompatible Changes Core/Standard entries, and the 51 still-open, extension-scoped New Functions entries this round's probe found — stays ahead of the deferred M3-C PHPStan adapter and the dropped M3-D Rector adapter | Catalogue and mapping work only; introduces no new adapter infrastructure |
| **M4 Framework packs** | `v0.4.x` | Planned: separately reviewable framework-specific guidance | Must not contaminate the PHP Core rule set |

## Repository structure

| Path | Purpose |
|---|---|
| `src/` | Symfony Console application, Composer/PHP policy resolver, rule registry/query engine, and explicit verification boundary |
| `resources/rules/` | 80 source-backed seed-rule JSON files, one rule per file |
| `schemas/` | Versioned rule, policy, and verification contracts |
| `docs/adr/` | Binding architecture decisions and trust boundaries |
| `tests/` | CLI, schema, and static-page verification |
| `site/` | Dependency-free GitHub Pages overview |
| `.github/workflows/` | CI, Pages, and release workflows |
| `skills/` | Distributable Agent Skill and Codex-compatible `AGENTS.md` snippet |
| `box.json.dist` | Committed PHAR build configuration; the build tool is installed in CI only |
| `tools/` | CI-only build helper scripts |

## Inspiration and attribution

Primary inspiration: [JetBrains/go-modern-guidelines](https://github.com/JetBrains/go-modern-guidelines).

Modern PHP Guidelines is an **independent implementation** inspired by its version-aware guidance model. The upstream repository uses the Apache-2.0 license ([upstream license](https://github.com/JetBrains/go-modern-guidelines/blob/main/LICENSE)); this repository does not copy upstream source files.

JetBrains and GoLand are trademarks of their respective owners. This project is not affiliated with, endorsed by, or sponsored by JetBrains.

This project also intentionally maintains a narrower product boundary than [netresearch/php-modernization-skill](https://github.com/netresearch/php-modernization-skill): its core is a version-aware PHP policy and rule-query engine, not a broad modernization orchestrator, framework convention guide, analyzer suite, or automatic fixer.

## Contributing and security

Read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting changes, especially the source-provenance and milestone-boundary requirements. Report security issues according to [SECURITY.md](SECURITY.md).

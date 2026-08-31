# Agent instructions

## M2 release and unreleased M3-A boundary

The published release is M2 (`0.2.0`). Implemented there: the `version`, `resolve`, `list-rules`, `explain`
and `doctor` commands, the Composer Semver policy resolver, the two-axis applicability engine, the seed
rule catalogue in `resources/rules/`, a CI-built PHAR attached to each release, and the agent-distribution
surfaces in `skills/`.

The current source tree additionally contains the unreleased M3-A verification foundation: the explicit
verify adapter surface, its canonical report schema, process boundary and
test-only fake adapter. Its production registry recognizes only a non-executing `phpcompatibility`
placeholder, which truthfully reports exit `7`; no real analyzer is invoked in M3-A. Do not add a real
PHPCompatibility, PHPStan or Rector adapter, framework packs, auto-fixes, network rule fetching, or agent
marketplace/plugin manifests unless the active task explicitly advances the corresponding later slice.

Rule files: one JSON file per rule in `resources/rules/`, basename equal to the rule `id`, and the id's
first dot-segment equal to its `category`. Categories: parser-level syntax is `language`, runtime-visible
functions/classes/attributes/constants and engine behaviour are `core`, anything needing a named
extension is `extension`. Files are stored in canonical `JsonPrinter` form (schema key order, 4-space
indent, `superseded_by` omitted when null, one trailing newline). On `modern_preference` and
`behavior_change` rules, `introduced_in` means "the minor the preferred API arrived in" / "the minor the
behavior changed in" so the rule can be gated on the feature axis; say so in the rule's `details`. Every
lifecycle fact needs an official PHP source URL
(`https://raw.githubusercontent.com/php/php-src/php-X.Y.0/UPGRADING`) and a `checked_at` date; mark
uncertainty instead of guessing.

## Agent distribution surfaces

`skills/php-modern-guidelines/` holds the Claude Agent Skill (`SKILL.md` plus `references/`);
`skills/agents-md/SNIPPET.md` holds the plain-Markdown wrapper a consuming project pastes into its own
`AGENTS.md`. Both distinguish the metadata-only core from the explicit verification boundary. Never write
text implying that M3-A runs a real analyzer, edits or fixes a target project, or that its unreleased
surface is already present in the `v0.2.0` release asset.

This text is contract-tested, not review-tested. Only content inside backticks is checked: every command,
option, exit code, rule id and warning code written in backticks must exist in the real CLI, and every
fenced `console` block is executed and compared to its documented output byte-for-byte. Three tokens are
reserved in `skills/`: `/path/to/app` in a command line means `tests/fixtures/projects/caret-8-2`, and
`<app>` and `<version>` in an expected output body mean that project root and the installed version. Do
not replace `<version>` with a literal version — `doctor` prints the version, and the placeholder is what
keeps the examples true across releases. Options belonging to foreign commands (`git`, `composer`, `curl`,
`sha256sum`, `cp`) are not contract-checked; options on a `php-modern-guidelines` line are. Adding a
command or an option without documenting it also fails the suite. After editing anything under `skills/`,
run `vendor/bin/phpunit tests/Unit/Skill tests/Integration/SkillExamplesTest.php`.

A foreign command line is exempt from *every* check, not just option checking, so it is the one part of
this text that no test can keep true: run it before you write it, and if it genuinely cannot be run from
your environment, say so where you record the change instead of implying it was. In particular the install
instructions document only the `git clone` checkout and the PHAR download. This package is not published on
Packagist, so `composer require` is not an install path and must not be written as one.

Only the four executed golden examples use a ` ```console ` fence. Every other shell block — the install
lines above all — uses ` ```bash `; a `console` fence makes the example runner try to execute the line and
fail on the first `curl` or `sha256sum`.

Some expected-output blocks contain lines made entirely of spaces, and they are compared byte-for-byte. Do
not run a trailing-whitespace-stripping formatter over anything under `skills/`.

The PHAR build tool is installed in CI only (ADR-007). `box.json.dist` is committed and unit-tested for
internal consistency without the tool being installed; never add it to `composer.json`. The archive is
content-deterministic within one build job; it is not byte-reproducible, and dependency resolution is not
pinned across builds because this package commits no `composer.lock`.

## Non-negotiable future contracts

- Use Composer Semver for Composer constraints; never approximate complete constraint semantics with regex.
- Keep feature ceiling (lowest supported minor) separate from lifecycle ceiling (highest known supported minor).
- Core commands must be deterministic and read-only: no target code execution, analyzed-project `vendor/autoload.php`, Composer scripts/plugins, network calls, or target-repository writes.
- Verification must remain explicit, policy-aware and zero-mutation. Time, captured output and process trees stay bounded; analyzer temporary paths stay outside the target; missing executables, unsupported projections and unmapped findings stay visible. M3-A itself runs no real analyzer.
- PHP language/Core/bundled-extension facts require an official PHP source URL and review date. Mark uncertainty instead of guessing.
- Keep generated output stable unless a caller explicitly requests timestamps.

## Attribution

This is an independent implementation inspired by https://github.com/JetBrains/go-modern-guidelines. Do not present it as an official JetBrains project or copy source files without preserving applicable notices. Keep the distinction from https://github.com/netresearch/php-modernization-skill clear: this project's planned core is a narrow policy and rule-query engine.

## Checks

Run `composer check` after PHP changes when dependencies are available. Validate every file in `schemas/`
after schema changes. Known PHP minors live only in `src/Php/KnownPhpMinors.php`. Diagnostic check ids,
their order and their detail keys live only in `src/Diagnostics/DoctorRunner.php`.

# Agent instructions

## M1 boundary

This repository is at M1 (`0.1.0`). Implemented: the `version`, `resolve`, `list-rules` and `explain`
commands, the Composer Semver policy resolver, the two-axis applicability engine, and the seed rule
catalogue in `resources/rules/`. Do not add framework packs, analyzer or Rector adapters, auto-fixes,
PHAR release automation, network rule fetching, or agent/plugin manifests unless the active task
explicitly advances the milestone.

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

## Non-negotiable future contracts

- Use Composer Semver for Composer constraints; never approximate complete constraint semantics with regex.
- Keep feature ceiling (lowest supported minor) separate from lifecycle ceiling (highest known supported minor).
- Core commands must be deterministic and read-only: no target code execution, analyzed-project `vendor/autoload.php`, Composer scripts/plugins, network calls, or target-repository writes.
- PHP language/Core/bundled-extension facts require an official PHP source URL and review date. Mark uncertainty instead of guessing.
- Keep generated output stable unless a caller explicitly requests timestamps.

## Attribution

This is an independent implementation inspired by https://github.com/JetBrains/go-modern-guidelines. Do not present it as an official JetBrains project or copy source files without preserving applicable notices. Keep the distinction from https://github.com/netresearch/php-modernization-skill clear: this project's planned core is a narrow policy and rule-query engine.

## Checks

Run `composer check` after PHP changes when dependencies are available. Validate both files in `schemas/` after schema changes. Known PHP minors live only in `src/Php/KnownPhpMinors.php`.

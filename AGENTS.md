# Agent instructions

## M0 boundary

This repository is at M0 (`0.0.1`). Only the `version` CLI command and the schema contracts are implemented. Do not add resolver behavior, rules, framework packs, analyzers, adapters, auto-fixes, PHAR release automation, or agent/plugin manifests unless the active task explicitly advances the milestone.

## Non-negotiable future contracts

- Use Composer Semver for Composer constraints; never approximate complete constraint semantics with regex.
- Keep feature ceiling (lowest supported minor) separate from lifecycle ceiling (highest known supported minor).
- Core commands must be deterministic and read-only: no target code execution, analyzed-project `vendor/autoload.php`, Composer scripts/plugins, network calls, or target-repository writes.
- PHP language/Core/bundled-extension facts require an official PHP source URL and review date. Mark uncertainty instead of guessing.
- Keep generated output stable unless a caller explicitly requests timestamps.

## Attribution

This is an independent implementation inspired by https://github.com/JetBrains/go-modern-guidelines. Do not present it as an official JetBrains project or copy source files without preserving applicable notices. Keep the distinction from https://github.com/netresearch/php-modernization-skill clear: this project's planned core is a narrow policy and rule-query engine.

## Checks

Run `composer check` after PHP changes when dependencies are available. Validate both files in `schemas/` after schema changes.

# AGENTS.md snippet

Paste the block below into your project's `AGENTS.md`. It carries the same contract as the
`php-modern-guidelines` Claude Agent Skill for agents that read `AGENTS.md` by convention instead of
using a skill mechanism — no YAML, no tool schema, no registration step.

```markdown
## PHP version policy

Before generating or changing PHP code, run the tool's `resolve` command against this project. The
tool lives outside this project, so name this project's path explicitly instead of relying on the
working directory — from a source checkout of the tool:
php bin/php-modern-guidelines resolve --project-root=/path/to/your/project
or with the released PHAR:
php php-modern-guidelines.phar resolve --project-root=/path/to/your/project
Either form reads the project's declared PHP compatibility range from `composer.json` and
`composer.lock`.

The result carries two separate ceilings, never one "the project's PHP version": the
`feature_ceiling` is the lowest PHP minor the project must still run on and bounds which syntax and
APIs may be emitted; the `lifecycle_ceiling` is the highest known allowed PHP minor and bounds which
deprecations and removals must be considered. Do not collapse the two.

Follow up with `list-rules` (filtered to the change you are making) and `explain` for any rule that
applies, before acting on it.

The published `v0.2.0` release stops there. The current unreleased M3-A source also exposes an explicit
verification adapter interface after policy and rule consultation. Its production
registry recognizes only `phpcompatibility`. For example:
php bin/php-modern-guidelines verify phpcompatibility --executable=/path/to/phpcs --project-root=/path/to/your/project --json
This adapter launches the selected PHP_CodeSniffer, so its exit `7` means that tool is unavailable
rather than that project code was scanned; treat its findings as evidence to check, and do not claim
PHPStan or Rector verification, because neither is implemented.

The core tool is metadata-only and never edits, executes or fixes the project. Its existing exit codes:
`2` means the input (usually `composer.json`) was invalid; `3` means an unknown rule id was passed to
`explain`; `4` means the declared PHP constraint contains no PHP minor this tool knows; `5` means the
tool's own rule data is invalid. Verification adds `6` for completed-with-findings, `7` for unavailable,
`8` for adapter failure and `9` for unsupported exact policy projection. A `verify` outcome with exit
`0`, `6`, `7`, `8` or `9` is a complete report on stdout with empty stderr; invalid invocations keep
stdout empty. Run `doctor` if the core tool will not answer, and report what it says rather than guessing.
```

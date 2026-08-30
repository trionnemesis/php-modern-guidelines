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

This tool is read-only: it never edits, executes or fixes the project. Its exit codes: `2` means the
input (usually `composer.json`) was invalid; `3` means an unknown rule id was passed to `explain`; `4`
means the declared PHP constraint contains no PHP minor this tool knows; `5` means the tool's own rule
data is invalid. Run `doctor` if the tool will not answer, and report what it says rather than guessing.
```

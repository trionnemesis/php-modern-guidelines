# CLI contract

The complete, mechanically-checked reference for `php-modern-guidelines`'s commands, options, exit
codes and JSON shapes. `SKILL.md` links here instead of repeating any of it.

The published `v0.2.0` release contains the M2 commands. The current source tree additionally exposes
the unreleased M3-A `verify` contract described below. M3-A has no real analyzer adapter: its production
`phpcompatibility` registration is a non-executing placeholder that reports `unavailable` truthfully.

## Global options

Every command additionally accepts the options Symfony Console merges onto every application:

| Option | Shortcut | Meaning |
| --- | --- | --- |
| `--help` | `-h` | Display help for the given command. |
| `--silent` | | Do not output any message. |
| `--quiet` | `-q` | Only errors are displayed. |
| `--version` | `-V` | Display the application version and exit. |
| `--ansi` | | Force ANSI output; negatable to disable it. |
| `--no-interaction` | `-n` | Do not ask any interactive question. |
| `--verbose` | `-v`/`-vv`/`-vvv` | Increase output verbosity. |

`version` takes no options of its own; it prints the application name followed by its version and
exits `0`.

## Shared policy options

`resolve`, `list-rules`, `explain`, `doctor` and `verify` all accept these four options:

| Option | Shortcut | Meaning |
| --- | --- | --- |
| `--project-root` | `-r` | Directory to read `composer.json` / `composer.lock` from. Defaults to the current working directory. |
| `--php` | | Explicit PHP version or Composer constraint, overriding range inference. |
| `--mode` | `-m` | One of `range-safe`, `single-target`, `runtime-observed`. Defaults to `range-safe`. |
| `--json` | | Emit machine-readable JSON on stdout instead of human text. |

### `resolve`

Resolves the two-axis PHP compatibility policy for a project. Its native options are exactly the four
shared policy options above:

| Option | Shortcut | Meaning |
| --- | --- | --- |
| `--project-root` | `-r` | Directory to read `composer.json` / `composer.lock` from. Defaults to the current working directory. |
| `--php` | | Explicit PHP version or Composer constraint, overriding range inference. |
| `--mode` | `-m` | One of `range-safe`, `single-target`, `runtime-observed`. Defaults to `range-safe`. |
| `--json` | | Emit machine-readable JSON on stdout instead of human text. |

### `list-rules`

Lists the bundled PHP rules, filtered by the resolved policy. Alias: `rules`.

| Option | Meaning |
| --- | --- |
| `--kind` | Filter by rule kind. Repeatable. |
| `--category` | Filter by rule category. Repeatable. |
| `--priority` | Filter by rule priority. Repeatable. |
| `--status` | Filter by applicability status. Repeatable. |
| `--extension` | Filter by extension. |
| `--minor` | Keep only rules affecting this PHP minor. |
| `--all` | Include `not_in_range` rules that are otherwise hidden. |
| `--rules-dir` | Advanced/testing: load rules from this directory instead of the bundled one. |

### `explain`

Explains a single rule and its applicability under the resolved policy. Takes one required argument,
the rule id, plus:

| Option | Meaning |
| --- | --- |
| `--rules-dir` | Advanced/testing: load rules from this directory instead of the bundled one. |

### `doctor`

Diagnoses this tool's own inputs and installation for a project — never the target project's code.
Its nine checks run in a fixed order: `cli.build`, `project.root`, `project.composer_json`,
`project.composer_lock`, `project.php_declarations`, `policy.resolution`, `schemas.available`,
`rules.directory`, `rules.load`. A check that could not run because an earlier one failed is reported
as `skipped`, never as passing.

| Option | Meaning |
| --- | --- |
| `--rules-dir` | Advanced/testing: load rules from this directory instead of the bundled one. |

### `verify`

Collects one deterministic, policy-aware advisory-evidence report from an explicitly selected adapter.
It takes one required adapter argument and one additional required option:

```bash
php bin/php-modern-guidelines verify phpcompatibility --executable=/path/to/phpcs --project-root=/path/to/app --json
```

| Input | Meaning |
| --- | --- |
| `adapter` | Adapter id. M3-A production builds recognize only `phpcompatibility`. |
| `--executable` | Path or `PATH` name of an already-installed executable selected by the caller. |

The four shared policy options remain authoritative: the adapter consumes the resolved policy rather
than the PHP runtime executing this CLI. The CLI never installs a tool, accepts arbitrary analyzer
arguments, loads the target project's autoloader, or writes analyzer output under the project root.

M3-A is an unreleased foundation, not a PHPCompatibility implementation. Its placeholder checks whether
the selected executable exists but never starts it. A missing executable and an existing executable with
no implemented adapter both return a complete `unavailable` report with exit `7`; they differ by the
stable reason in that report. A real PHPCompatibility parser and invocation belong to M3-B.

### exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success. |
| `1` | Unexpected internal error — a bug in the tool, not an input problem. |
| `2` | Invalid input: malformed or unreadable Composer JSON, an unparseable constraint, an unknown option value, or a minor outside known coverage. |
| `3` | The rule id given to `explain` does not exist. |
| `4` | A valid PHP constraint contains none of the PHP minors this tool knows, so policy resolution cannot proceed. |
| `5` | Invalid rule data: a malformed rule file, a duplicate id, or a filename that does not match its id. |
| `6` | Verification completed and produced one or more advisory findings. |
| `7` | The selected verification adapter or executable is unavailable. |
| `8` | The verification adapter could not complete execution. |
| `9` | The resolved PHP policy cannot be projected exactly into the selected analyzer. |

For `resolve`, `list-rules` and `explain`, a non-zero exit writes a human-readable error line to stderr
and leaves stdout byte-empty, so a `--json` caller never receives a partial document. `doctor` is the
one documented exception: on a non-zero exit it prints the complete report on stdout and leaves stderr
empty, because the report is the diagnosis rather than a failure to answer — except for a mistake in
`doctor`'s own options, which is rejected before any check runs and, like every other command, prints
nothing on stdout.

`verify` has a separate report rule. Outcomes `0`, `6`, `7`, `8` and `9` write one complete human or
canonical JSON report to stdout and leave stderr byte-empty. Exit `0` means verification completed with
no findings; `6` means it completed with findings. The other three statuses retain the reason no report
consumer should have to infer. A mistake in the `verify` invocation, a policy-resolution error, invalid
rule data, or an unexpected internal failure keeps the existing exit `2`, `4`, `5` or `1` behavior:
stdout is byte-empty and stderr receives the error, so a JSON consumer never sees a partial document.

### resolve --json keys

`resolve --json` is exactly a `policy.schema.json` instance, with no wrapper object.
`list-rules --json`, `explain --json` and `doctor --json` additionally carry an `output_version`
field that `resolve --json` does not have.

- `schema_version`
- `mode`
- `project_root`
- `declared_constraint`
- `allowed_minors`
- `feature_ceiling`
- `lifecycle_ceiling`
- `platform_override`
- `observed_runtime`
- `coverage`
- `confidence`
- `sources`
- `warnings`

### verify --json keys

`verify --json` satisfies `schemas/verification.schema.json`. Its top-level keys are deterministic and
ordered as follows:

- `output_version`
- `status`
- `exit_code`
- `adapter`
- `policy`
- `invocations`
- `summary`
- `reason`
- `rule_contexts`
- `findings`

The report distinguishes `success`, `findings`, `unavailable`, `failed` and `unsupported_policy`.
The `planned_invocations` member of `policy` records the complete plan validated before execution; top-level
`invocations` records only processes actually attempted, so an early failure does not invent later runs.
Each entry distinguishes a `tool_probe` from `analysis`, uses the `project_root` working-directory role,
and records its bounded timeout and `sanitized` environment role. Executable paths in evidence are
project-relative or reduced to a stable external basename so machine-specific prefixes are not disclosed.
The native runner caps stdout and stderr separately. Exceeding either limit terminates the process group,
records `output_limit_exceeded`, and uses the stable failure reason `adapter.output_limit_exceeded`; partial
output never becomes partial findings.
External findings retain their external ids and use `mapped` or `unmapped` mapping status; exact mapped
project rule ids are arrays, never comma-separated strings. No timestamp is emitted.

## Worked `explain` example

The full output of `explain` is long — it includes the rule's guideline, new-code and existing-code
prose, background details, and worked before/after code examples — so it lives here rather than in
`SKILL.md`.

<!-- The code examples below contain lines that are pure trailing whitespace (indentation on an
     otherwise-blank line inside the `before:`/`after:` samples). That whitespace is part of the
     expected output and byte-compared by the test suite — do not let an editor or formatter trim it. -->

```console
$ php bin/php-modern-guidelines explain language.property_hooks --project-root=/path/to/app
language.property_hooks — Property hooks
  category            language
  kind                feature
  priority            P1
  introduced in       8.4
  deprecated in       -
  removed in          -
  extension           -
  behavior risk       none

Applicability (policy: range-safe, feature ceiling 8.2, lifecycle ceiling 8.5)
  status              forbidden_above_feature_ceiling
  axis                feature
  usable across range no
  affected minors     8.4, 8.5
  reading             Exists on newer allowed minors but not on the feature ceiling — do not emit it.

Guideline
  Prefer property hooks (`get`/`set` blocks on a property declaration) over a manual getter/setter
  method pair, and over a private backing property plus a read-only computed getter, once the
  feature ceiling reaches PHP 8.4.

New code
  On a feature ceiling of 8.4 or above, prefer a `get`/`set` hook on the property declaration
  itself over a hand-written getXxx()/setXxx() method pair backed by a private property; use a
  get-only hook with no backing property at all for a value that is purely computed from other
  state.

Existing code
  Do not bulk-convert existing getter/setter method pairs to property hooks. Converting changes
  the class's public surface from methods to a plain property access, so every call site
  (`$obj->getFoo()` / `$obj->setFoo($v)`) must be updated to `$obj->foo` / `$obj->foo = $v`, and
  any interface the class implements would need updating too. Migrate a class only when it is
  already being revised for another reason, and only once the whole call-site graph can be updated
  in the same change.

Details
  PHP 8.4 implemented property hooks (RFC property-hooks): a property declaration may carry a
  `get` block, a `set` block, or both, which run when the property is read or written in place of
  a separate getter/setter method pair. A property whose hook only computes a value from other
  properties needs no backing storage at all, giving a genuine virtual/computed property; a `set`
  hook can validate or transform an incoming value before it is stored. Inside a hook body,
  `$this->propName` refers to the property's own backing storage rather than re-entering the hook,
  so a straightforward `set` hook that assigns `$this->propName = $value` does not recurse.
  Property hooks may also be declared on an interface property, which implementing classes must
  then satisfy. This is additive syntax: a property with no hooks behaves exactly as before, and
  existing getter/setter methods keep working unchanged. No PHP 8.2, 8.3 or 8.5 UPGRADING file
  mentions property hooks, so the feature has no earlier introduction and no recorded deprecation
  or removal.

Examples
  1. before:
       final class TemperatureReading
       {
           private float $celsius;
       
           public function __construct(float $celsius)
           {
               $this->setCelsius($celsius);
           }
       
           public function getCelsius(): float
           {
               return $this->celsius;
           }
       
           public function setCelsius(float $celsius): void
           {
               if ($celsius < -273.15) {
                   throw new \InvalidArgumentException('Below absolute zero.');
               }
       
               $this->celsius = $celsius;
           }
       }
     after:
       final class TemperatureReading
       {
           public float $celsius {
               set(float $value) {
                   if ($value < -273.15) {
                       throw new \InvalidArgumentException('Below absolute zero.');
                   }
       
                   $this->celsius = $value;
               }
           }
       
           public function __construct(float $celsius)
           {
               $this->celsius = $celsius;
           }
       }
  2. before:
       final class Rectangle
       {
           public function __construct(
               private float $width,
               private float $height,
           ) {
           }
       
           public function getArea(): float
           {
               return $this->width * $this->height;
           }
       }
     after:
       final class Rectangle
       {
           public float $area {
               get => $this->width * $this->height;
           }
       
           public function __construct(
               private float $width,
               private float $height,
           ) {
           }
       }

Sources
  php_source_upgrading  https://raw.githubusercontent.com/php/php-src/php-8.4.0/UPGRADING  2026-08-30
```

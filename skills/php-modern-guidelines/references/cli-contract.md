# CLI contract

The complete, mechanically-checked reference for `php-modern-guidelines`'s commands, options, exit
codes and JSON shapes. `SKILL.md` links here instead of repeating any of it.

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

`resolve`, `list-rules`, `explain` and `doctor` all accept these four options:

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

### exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success. |
| `1` | Unexpected internal error — a bug in the tool, not an input problem. |
| `2` | Invalid input: malformed or unreadable Composer JSON, an unparseable constraint, an unknown option value, or a minor outside known coverage. |
| `3` | The rule id given to `explain` does not exist. |
| `4` | A valid PHP constraint contains none of the PHP minors this tool knows, so policy resolution cannot proceed. |
| `5` | Invalid rule data: a malformed rule file, a duplicate id, or a filename that does not match its id. |

For `resolve`, `list-rules` and `explain`, a non-zero exit writes a human-readable error line to stderr
and leaves stdout byte-empty, so a `--json` caller never receives a partial document. `doctor` is the
one documented exception: on a non-zero exit it prints the complete report on stdout and leaves stderr
empty, because the report is the diagnosis rather than a failure to answer — except for a mistake in
`doctor`'s own options, which is rejected before any check runs and, like every other command, prints
nothing on stdout.

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

---
name: php-modern-guidelines
description: Consult the Modern PHP Guidelines CLI (resolve, list-rules, explain, doctor, and the unreleased verify contract) before writing or changing PHP in a project, so generated code stays inside the project's declared Composer PHP range. Use this when adding or editing PHP code, choosing between an older and a newer PHP idiom, reviewing a PHP diff for version safety, or answering which PHP versions a project supports.
license: Apache-2.0. LICENSE has the complete terms.
---

## What this tool is

`php-modern-guidelines` is a read-only, deterministic command-line tool. Given a target project's
`composer.json` (and `composer.lock` when present), it resolves the project's declared PHP
compatibility range and answers queries about which PHP syntax and APIs are safe to emit, and which
deprecations or removals apply, against that range. It never edits, executes or fixes the project it is
pointed at, and it needs no network access to run.

The published `v0.2.0` release is M2. The current source tree also contains the unreleased M3-A
verification contract. Its production `phpcompatibility` adapter is only a truthful unavailable
placeholder: it checks the selected executable but never launches PHPCompatibility or any other
analyser. Do not present M3-A output as a completed scan.

## When to use it

- Before writing new PHP in a project you have not worked in before.
- Before choosing a newer PHP syntax construct or standard-library API over an older one.
- When reviewing a diff for whether it is safe on every PHP minor the project supports.
- When asked which PHP versions a project supports.
- When a generated snippet fails or looks suspicious on the project's minimum PHP version.

## The two-axis rule

Every resolved policy carries two separate ceilings, and they must never be collapsed into one "the
project's PHP version": the **feature ceiling** is the lowest PHP minor the project must still run on,
and it bounds which syntax and APIs may be emitted; the **lifecycle ceiling** is the highest PHP minor
the declared range is known to allow, and it bounds which deprecations and removals must be considered.
See `references/two-axis-policy.md` for the full semantics, the coverage statuses, and the three
resolution modes.

## Required workflow

1. Run `resolve` against the project root to get the current policy.
2. Read `feature_ceiling` and `lifecycle_ceiling` from that output — never just one PHP version.
3. Run `list-rules` filtered to the kind of change you are about to make.
4. Run `explain` for any rule that applies, passing its rule id, before acting on it.
5. Use `verify` only after policy and rule consultation, and only when a relevant real adapter is
   implemented and available. M3-A has no such adapter, so its exit `7` is capability evidence, not a
   code-verification result.
6. Obey the guidance the tool gives, and repeat its warnings verbatim rather than paraphrasing them
   away.

## Commands

Every example below is for a project declaring `require.php: ^8.2`. `<app>` stands for the caller's
project root and `<version>` for the installed `php-modern-guidelines` version.

Resolve the policy first:

```console
$ php bin/php-modern-guidelines resolve --project-root=/path/to/app
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

Then filter the rule catalogue to what you are about to write:

```console
$ php bin/php-modern-guidelines list-rules --project-root=/path/to/app --kind=deprecated
PHP policy: range-safe, feature ceiling 8.2, lifecycle ceiling 8.5 (allowed 8.2, 8.3, 8.4, 8.5)
Rules: 4 of 16 shown

  [deprecated_across_range]          P2  deprecated           core.dynamic_properties
      Creation of dynamic properties is deprecated
  [deprecated_in_range]              P2  deprecated           extension.curl_close
      curl_close() is deprecated
  [deprecated_across_range]          P2  deprecated           language.dollar_brace_string_interpolation
      "${var}"/"${expr}" string interpolation is deprecated
  [deprecated_in_range]              P2  deprecated           language.implicitly_nullable_parameter_types
      Implicitly nullable parameter types are deprecated
```

Before acting on a specific rule, read its full explanation — see `references/cli-contract.md` for a
complete worked `explain` example; its output is long, so it is not repeated here.

If the tool will not answer, run `doctor` before guessing why:

```console
$ php bin/php-modern-guidelines doctor --project-root=/path/to/app
Doctor: warn

  [ok]      cli.build                php-modern-guidelines <version>, source distribution
  [ok]      project.root             <app>
  [ok]      project.composer_json    composer.json present, valid JSON object
  [ok]      project.composer_lock    composer.lock absent
  [ok]      project.php_declarations declared PHP values read, no input warnings
  [warn]    policy.resolution        range-safe: feature 8.2, lifecycle 8.5, coverage coverage_gap (known 8.2-8.5, open upper bound), 1 warning(s)
  [ok]      schemas.available        rule.schema.json ok, policy.schema.json ok
  [ok]      rules.directory          bundled rules directory, 16 rule file(s)
  [ok]      rules.load               16 rule(s) loaded

Details
  cli.build
    version                 <version>
    distribution            source
  project.root
    path                    <app>
    is_directory            yes
  project.composer_json
    path                    composer.json
    present                 yes
    readable                yes
    json                    object
  project.composer_lock
    path                    composer.lock
    present                 no
    readable                no
    json                    -
  project.php_declarations
    require_php             ^8.2
    conflict_php            -
    config_platform_php     -
    platform_overrides_php  -
    input_warnings          -
    error                   -
  policy.resolution
    mode                    range-safe
    allowed_minors          8.2, 8.3, 8.4, 8.5
    feature_ceiling         8.2
    lifecycle_ceiling       8.5
    platform_override       -
    observed_runtime        -
    coverage                coverage_gap (known 8.2-8.5, open upper bound)
    confidence              declared
    warnings                coverage.open_upper_bound_bounded
    error                   -
  schemas.available
    rule_schema             ok
    policy_schema           ok
  rules.directory
    source                  bundled
    file_count              16
  rules.load
    loaded                  16
    error                   -
```

The unreleased M3-A source exposes this explicit verification shape:

```bash
php bin/php-modern-guidelines verify phpcompatibility --executable=/path/to/phpcs --project-root=/path/to/app --json
```

This placeholder always produces a complete unavailable report and never runs the selected executable.
If the executable cannot be found, the report says so; if it exists, the report says that the adapter
capability is not implemented in this build. See `references/cli-contract.md` for the full contract.

## Exit codes

`resolve`, `list-rules`, `explain`, `doctor` and `verify` share one exit-code table; the full table is in
`references/cli-contract.md`. For `resolve`, `list-rules` and `explain`, a non-zero exit writes one
human-readable error line to stderr and leaves stdout byte-empty, so a `--json` consumer never receives
a partial document. `doctor` is the one documented exception: on a non-zero exit it prints its
**complete** report on stdout with stderr empty, because the report is the diagnosis — the only
exception to that exception is a mistake in `doctor`'s own options, which is rejected before any check
runs and prints nothing on stdout, exactly like every other command. Do not discard `doctor`'s output
just because its exit code is non-zero.

`verify` emits a complete report on stdout with stderr empty for exits `0`, `6`, `7`, `8` and `9`.
These mean completed without findings, completed with findings, unavailable adapter/tool, adapter
execution failure, and unsupported exact policy projection respectively. Invocation and core-input
errors retain the existing empty-stdout behavior. In M3-A, production verification can only return the
truthful unavailable outcome; no real analyzer is executed.

## Hard limits

- The core commands are metadata-only and never edit, execute, or write to the target project. The
  unreleased M3-A `verify` placeholder also runs no analyzer. Later real adapters must remain explicit,
  isolated child processes and must prove zero target writes.
- It covers PHP 8.2 through 8.5 only.
- A `coverage_gap` coverage status, or a `coverage.below_known_min`,
  `coverage.open_upper_bound_bounded` or `coverage.open_upper_bound_unbounded` warning, must be
  reported to the user, never silently assumed away.
- The agent must not invent a PHP lifecycle fact the tool did not state.
- If the tool fails to answer, run `doctor` and report what it says rather than guessing at the cause.

## Installing the tool

Two install paths exist. There is no `composer require` path: this package is not published on
Packagist yet.

Source checkout:

```bash
git clone https://github.com/trionnemesis/php-modern-guidelines.git
cd php-modern-guidelines
composer install
php bin/php-modern-guidelines version
```

Released PHAR, verified against its checksum:

```bash
curl -fsSL -o php-modern-guidelines.phar \
  https://github.com/trionnemesis/php-modern-guidelines/releases/latest/download/php-modern-guidelines.phar
curl -fsSL -o php-modern-guidelines.phar.sha256 \
  https://github.com/trionnemesis/php-modern-guidelines/releases/latest/download/php-modern-guidelines.phar.sha256
sha256sum -c php-modern-guidelines.phar.sha256
php php-modern-guidelines.phar version
```

## Reference files

- `references/cli-contract.md` — the complete option tables, exit-code table, deterministic `resolve`
  and `verify` JSON key lists, and the full `explain` example.
- `references/two-axis-policy.md` — the two-axis semantics, the coverage statuses, and the three
  resolution modes.

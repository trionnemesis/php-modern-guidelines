---
name: php-modern-guidelines
description: Consult the Modern PHP Guidelines CLI (resolve, list-rules, explain, doctor, and verify) before writing or changing PHP in a project, so generated code stays inside the project's declared Composer PHP range. Use this when adding or editing PHP code, choosing between an older and a newer PHP idiom, reviewing a PHP diff for version safety, or answering which PHP versions a project supports.
license: Apache-2.0. LICENSE has the complete terms.
---

## What this tool is

`php-modern-guidelines` is a read-only, deterministic command-line tool. Given a target project's
`composer.json` (and `composer.lock` when present), it resolves the project's declared PHP
compatibility range and answers queries about which PHP syntax and APIs are safe to emit, and which
deprecations or removals apply, against that range. It never edits, executes or fixes the project it is
pointed at, and it needs no network access to run.

The published `v0.3.0` release includes the `verify` surface. Its production `phpcompatibility`
adapter runs a caller-selected, already-installed PHP_CodeSniffer with the PHPCompatibility standard as
an isolated child process and reports advisory evidence, never an automatic fix. It installs nothing,
writes nothing under the target project, and always projects the resolved policy — never the PHP
version running this CLI — onto the analyzer. Current source builds scan explicit top-level operands and
omit the exact project-root `vendor/` directory; planned and executed invocation evidence records those
operands, so dependency scoping is visible rather than an implicit clean result.

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
   implemented and available. The production `phpcompatibility` adapter is such an adapter, but it
   needs an already-installed PHP_CodeSniffer with the PHPCompatibility standard registered, selected
   with `--executable`; its exit `7` is capability evidence about the selected tool, not a
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
Rules: 34 of 64 shown

  [deprecated_in_range]              P2  deprecated           core.assert_options
      assert_options() and its ASSERT_* constants are deprecated
  [deprecated_in_range]              P2  deprecated           core.chr_ord_byte_range
      `chr()` outside `[0, 255]` and `ord()` on non-single-byte strings are deprecated
  [deprecated_in_range]              P2  deprecated           core.constant_redeclaration
      Constant redeclaration is deprecated
  [deprecated_in_range]              P2  deprecated           core.csv_escape_parameter
      Relying on the default `$escape` parameter of the CSV functions is deprecated
  [deprecated_in_range]              P2  deprecated           core.date_rfc7231
      `DATE_RFC7231` and `DateTimeInterface::RFC7231` are deprecated
  [deprecated_in_range]              P2  deprecated           core.directory_functions_implicit_handle
      `readdir()`, `rewinddir()`, and `closedir()` without an explicit handle are deprecated
  [deprecated_across_range]          P2  deprecated           core.dynamic_properties
      Creation of dynamic properties is deprecated
  [deprecated_in_range]              P2  deprecated           core.e_strict_constant
      The E_STRICT constant is deprecated
  [deprecated_in_range]              P2  deprecated           core.get_class_without_arguments
      `get_class()`/`get_parent_class()` without arguments is deprecated
  [deprecated_in_range]              P2  deprecated           core.get_defined_functions_exclude_disabled
      Passing an explicit `$exclude_disabled` argument to `get_defined_functions()` is deprecated
  [deprecated_in_range]              P2  deprecated           core.http_response_header
      The `$http_response_header` predefined variable is deprecated
  [deprecated_in_range]              P2  deprecated           core.lcg_value
      `lcg_value()` is deprecated
  [deprecated_in_range]              P2  deprecated           core.null_array_offset
      Using `null` as an array offset or in `array_key_exists()` is deprecated
  [deprecated_in_range]              P2  deprecated           core.output_in_output_handler
      Producing output (e.g. `echo`) within a user output handler is deprecated
  [deprecated_across_range]          P2  deprecated           core.partially_supported_callables
      Partially supported callables are deprecated
  [deprecated_in_range]              P2  deprecated           core.register_argc_argv_ini
      `register_argc_argv` and deriving `$_SERVER['argc']`/`$_SERVER['argv']` from the query string are deprecated for non-CLI SAPIs
  [deprecated_in_range]              P2  deprecated           core.report_memleaks_ini
      The `report_memleaks` INI directive is deprecated
  [deprecated_in_range]              P2  deprecated           core.sleep_wakeup_magic_methods
      `__sleep()`/`__wakeup()` are soft-deprecated in favour of `__serialize()`/`__unserialize()`
  [deprecated_in_range]              P2  deprecated           core.socket_set_timeout
      The `socket_set_timeout()` alias function is deprecated
  [deprecated_in_range]              P2  deprecated           core.stream_context_set_option_arity
      Calling `stream_context_set_option()` with 2 arguments is deprecated
  [deprecated_in_range]              P2  deprecated           core.string_increment_operators
      `++`/`--` on empty, non-numeric, or non-alphanumeric strings
  [deprecated_in_range]              P2  deprecated           core.trigger_error_e_user_error
      Passing `E_USER_ERROR` to `trigger_error()` is deprecated
  [deprecated_in_range]              P2  deprecated           core.underscore_class_name
      Using `_` as a class name is deprecated
  [deprecated_across_range]          P2  deprecated           core.utf8_encode_decode
      `utf8_encode()` and `utf8_decode()` are deprecated
  [deprecated_in_range]              P2  deprecated           extension.curl_close
      curl_close() is deprecated
  [deprecated_in_range]              P2  deprecated           extension.curl_share_close
      curl_share_close() is deprecated
  [deprecated_in_range]              P2  deprecated           extension.finfo_close
      finfo_close() is deprecated
  [deprecated_in_range]              P2  deprecated           extension.mysqli_ping_kill_refresh
      `mysqli_ping()`, `mysqli_kill()` and `mysqli_refresh()` are deprecated
  [deprecated_in_range]              P2  deprecated           extension.mysqli_store_result_mode
      Passing an explicit `$mode` argument to `mysqli_store_result()` is deprecated
  [deprecated_in_range]              P2  deprecated           language.backtick_shell_exec
      Backtick shell-execution operator is deprecated
  [deprecated_in_range]              P2  deprecated           language.case_terminating_semicolon
      Terminating a `case`/`default` label with `;` instead of `:` is deprecated
  [deprecated_across_range]          P2  deprecated           language.dollar_brace_string_interpolation
      "${var}"/"${expr}" string interpolation is deprecated
  [deprecated_in_range]              P2  deprecated           language.implicitly_nullable_parameter_types
      Implicitly nullable parameter types are deprecated
  [deprecated_in_range]              P2  deprecated           language.non_canonical_cast_names
      (boolean), (integer), (double) and (binary) casts are deprecated
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
  [ok]      rules.directory          bundled rules directory, 64 rule file(s)
  [ok]      rules.load               64 rule(s) loaded

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
    file_count              64
  rules.load
    loaded                  64
    error                   -
```

The `verify` command exposes this explicit shape:

```bash
php bin/php-modern-guidelines verify phpcompatibility --executable=/path/to/phpcs --project-root=/path/to/app --json
```

This adapter runs the selected executable and reports the findings it produces, or reports truthfully
that the selected tool is unavailable. Before analysis it probes the selected executable in order: first
that it can be located, then that it reports a version, then that it has the PHPCompatibility standard
registered — a missing executable, a program that is not PHP_CodeSniffer, and a PHP_CodeSniffer
installation without that standard are three distinct unavailable reasons, all exit `7`. If the resolved
policy cannot be projected onto the analyzer's version range exactly, `verify` exits `9` rather than
approximating it. See `references/cli-contract.md` for the full contract.

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
errors retain the existing empty-stdout behavior. Production verification can return every one of those
outcomes. Each finding keeps the analyzer's external sniff identifier verbatim, carries a mapped
internal rule id only where a committed, reviewed mapping exists, and is preserved even when no mapping
exists.

## Hard limits

- The core commands are metadata-only and never edit, execute, or write to the target project. The
  `verify` surface is the one place that starts an external process, and it still never edits, fixes or
  writes to the target project. Every real adapter, including the shipped `phpcompatibility` adapter, is
  tested to leave the target tree byte-identical before and after every success and failure path.
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

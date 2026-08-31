# ADR-008: External verification adapter boundary

- Status: Accepted
- Date: 2026-08-31

## Context

ADR-006 keeps the policy and rule-query core metadata-only: it does not execute target-project code,
load the target project's autoloader, or write to the target project. M3 adds optional evidence from
external analyzers without changing that core boundary or turning this package into a generic analyzer
launcher, dependency installer, or remediation tool.

## Decision

### Explicit, preinstalled adapters only

Verification is exposed only through an explicit invocation of
`verify <adapter> --executable=<path-or-name>`. No existing command runs an adapter implicitly. The
caller must select an executable that is already installed; the CLI never installs, updates, downloads,
or registers an analyzer or any of its dependencies.

M3-A recognizes `phpcompatibility` through a non-executing boundary placeholder. If the selected
executable cannot be found, the result is `unavailable` with reason `adapter.executable_unavailable`. If it exists
but the real adapter is not implemented in the running build, the result is `unavailable` with reason
`adapter.capability_unavailable`. Neither path executes the selected program.

### Child-process isolation and zero target writes

A real adapter runs its analyzer in a separate child process. The php-modern-guidelines process never
requires the target project's `vendor/autoload.php` and never loads target-project code into its own PHP
runtime. Adapter argument vectors are constructed by committed adapter code; callers cannot pass an
arbitrary command or unrestricted analyzer arguments through the public CLI.

This isolation is a process boundary, not an operating-system filesystem or network sandbox. The project
therefore does not claim that `proc_open()` by itself prevents a child from writing files or opening a
network connection. A supported adapter must instead use a fixed, offline invocation whose documented
tool behavior and tests prove that the target tree remains byte-identical on successful, finding,
unavailable, failed, and unsupported-policy paths. If that guarantee cannot be demonstrated, the adapter
is not supported.

An adapter must not:

- write source files, caches, baselines, reports, generated configuration, or temporary configuration
  under `--project-root`;
- create, overwrite, or patch Composer, PHPCS, PHPStan, Rector, or project configuration;
- run Composer or another package manager, including install/update operations, plugins, or project
  scripts;
- require runtime network access; or
- load or execute the target project's autoloader or application code in the main process.

The low-level process runner is an internal implementation detail available only to committed adapters.
It uses an argument vector rather than a shell command, captures stdout, stderr, and the external exit or
terminating signal, and applies a bounded timeout. On supported Linux hosts it starts the analyzer through
a fixed `setsid` path, verifies that the child owns a dedicated process group, and signals that whole group
with TERM followed by KILL on timeout. It also kills any descendants left behind by a normally exited
leader before releasing the process id. This closes the timeout path over analyzer-created background
workers rather than stopping only the direct child. Stdout and stderr are each capped at 8 MiB; exceeding
either cap kills the process group and records `output_limit_exceeded` with the stable failure reason
`adapter.output_limit_exceeded`, so a verbose or malfunctioning analyzer cannot exhaust the parent before
cleanup. The cap preserves only the exact bounded prefix and no partial findings. It is not exposed as a
generic command-execution facility. Native execution fails closed unless non-blocking pipes, the PHP POSIX group-signal functions,
and a fixed compatible `setsid` launcher are available. A future implementation for Windows, macOS, or
another unsupported host needs a separately tested, equally bounded process-tree and capture strategy; it
must not fall back to a shell or silently weaken timeout behavior.

### Exact policy projection

Every adapter receives the `ResolvedPolicy` produced by the existing Composer Semver and two-axis policy
resolver. The PHP runtime executing this CLI is never substituted for that policy.

An analyzer may be invoked only when its version expression can represent the resolved allowed set
exactly. OR branches, exclusions, conflicts, open coverage, or any other policy shape must not be widened,
narrowed, flattened, or reconstructed from only the feature and lifecycle ceilings. If exact projection
cannot be proven, verification stops with `policy.projection_unsupported`. Multiple analyzer invocations
are allowed only when their union is proven semantically equivalent to the resolved policy and their
normalized result is deterministic.

Before any child process may start, an adapter must return a side-effect-free invocation plan. Each
descriptor states whether it is a `tool_probe` or policy-partitioned `analysis`, fixes the project-root
working-directory role, bounded timeout, and sanitized environment role, and uses only canonical
relative path operands. The runner supplies a small locale/timezone/color/process-discovery allow-list;
it does not inherit proxy, credential, home, user-config, or temporary-directory values. Instead,
`TMPDIR`, `TEMP`, and `TMP` share one fixed canonical writable directory proven outside the target;
execution fails closed when neither reviewed host path is safe. Probe
invocations carry no policy minors and therefore cannot counterfeit coverage. The
orchestration layer independently validates that plan and rejects a `supported` claim for a range-safe
policy whose known coverage is incomplete or open-ended, as well as overlapping, missing, or reordered
minor partitions. Adapter-specific projection code must additionally prove that its committed argument
vector represents the structured policy exactly; echoing the allowed-minor list is not itself a
projection proof. A failed multi-invocation run keeps the full validated plan separate from the subset of
invocations actually attempted, so its exit-`8` report remains truthful and complete.

### Provenance and mappings

External analyzer output is advisory evidence. It does not establish or replace an official PHP language,
Core, extension, deprecation, removal, or lifecycle fact. Official/source-backed rule context remains
separate from external compatibility findings, project or dependency deprecation annotations, and
proposed Rector transformations.

An external identifier maps to an internal rule id only through a committed, reviewed, test-covered exact
mapping. Message-text matching, fuzzy matching, regular-expression guesses over prose, and model judgment
are forbidden. Unmapped findings are retained with `mapping_status: unmapped`. Mapped internal ids are
represented as an ordered list so one-to-many and many-to-one relationships are not encoded as ambiguous
comma-separated strings.

Stable reports record the adapter id, detected tool version, the prevalidated normalized invocation plan,
actual invocation arguments and external exit status or terminating signal, policy fingerprint,
deterministic counts, project-relative finding paths, external
identifiers, mapping status, and mapped internal rule ids. Checkout-specific absolute paths and implicit
timestamps are not part of the contract. Human and JSON renderers communicate the same statuses and
counts; `schemas/verification.schema.json` is the canonical versioned JSON contract.

The JSON schema enforces the report's structure, outcome-specific fields, normalized path operands,
and local value constraints. Relationships that JSON Schema cannot express without duplicating the
producer model remain mandatory producer invariants: invocation ids are unique by id, actual
descriptors match their prevalidated plan, completed runs attempt the full plan, finding invocation
ids resolve to attempted analysis invocations, mapped rule ids resolve to the committed rule registry,
analysis partitions form the exact ordered policy union, and every summary count is derived from the
corresponding arrays and mappings. Schema-valid input that violates one of these relationships is not a
truthful verification report, and the producer rejects it before rendering.

Executable evidence retains a bare `PATH` name or a project-relative path. An absolute path outside the
project is reduced to a stable `<external>/<basename>` identity; the raw machine-specific prefix is used
only for read-only lookup/process startup and is not emitted in the report.

### Process exit and report contract

Existing exit codes `0` through `5` retain their current meanings. Verification adds:

| Code | Meaning |
| --- | --- |
| `6` | Verification completed and produced one or more findings. |
| `7` | The selected adapter or executable is unavailable. |
| `8` | The adapter was invoked but verification could not complete. |
| `9` | The resolved PHP policy cannot be projected exactly into the selected analyzer. |

Exit `0` means verification completed with no findings. Outcomes `6` through `9` still emit one complete,
deterministic human or JSON verification report on stdout and leave stderr empty, because the report is
the machine-readable answer. Invalid invocation, policy-resolution, rule-data, and unexpected internal
errors retain their existing exit codes and established empty-stdout/error-stderr behavior; they never
emit a partial JSON document.

### Rector is advisory and mechanically dry-run-only

Any Rector adapter must construct and enforce a dry-run invocation itself. No public option, adapter
request, documentation, skill, or agent surface may disable dry-run or expose apply, write, fix, update,
or remediation-loop behavior. If a supported Rector invocation cannot guarantee dry-run and an unchanged
target tree, Rector is omitted from the release rather than weakening this boundary.

### Test and PHAR boundary

The deterministic fake adapter exists only under the development test tree. It exercises completed,
findings, unavailable, failed, unsupported-policy, mapped, and unmapped outcomes, but is not a production
adapter and is not bundled into the PHAR.

M3-A has no production call site for the native runner: its placeholder never reaches `verify()`, and a
static boundary test forbids process-runner access from production adapter classes. Before M3-B may add
the first real adapter, the validated plan must be mechanically bound to execution through a core-owned
executor that derives the `ProcessRequest` and actual invocation record from the same descriptor. A real
adapter must not be allowed to run one request and self-report a different matching DTO. Until that gate
exists and is tested, no production adapter may execute an analyzer.

The PHAR contains the verification contract and orchestration code, not PHPCompatibility, PHPCS, PHPStan,
PHPStan deprecation rules, Rector, or their dependency trees. Its M3-A smoke test proves that `verify` is
present and reports the unavailable placeholder truthfully without executing or bundling an analyzer.

## Consequences

The existing metadata-only commands remain unchanged and no analyzer becomes a production dependency.
External execution is confined to a small, reviewable namespace and a single process-runner boundary,
while the rest of `src/` remains protected from process-execution primitives.

Each adapter must carry deterministic output tests, exact mapping tests, subprocess argument/capture/
timeout tests, path-normalization tests, and before/after target-tree integrity checks for every outcome.
An unavailable tool, unsupported policy, or unmapped finding remains explicit evidence rather than being
hidden, approximated, installed, or repaired automatically.

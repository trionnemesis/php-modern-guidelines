# ADR-006: Read-only core

- Status: Accepted
- Date: 2026-08-29

## Decision

The future `resolve`, `list`, `explain`, and `doctor` core commands will read metadata only. They must not execute target-project code, require the target project's `vendor/autoload.php`, run Composer scripts/plugins, connect to the network, or write to an analyzed repository.

## Consequences

The CLI has a clear trust boundary appropriate for pre-generation agent consultation. Any future mutation or external adapter remains explicit and opt-in.

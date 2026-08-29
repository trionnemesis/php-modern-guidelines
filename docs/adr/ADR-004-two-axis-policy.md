# ADR-004: Two-axis range-safe policy

- Status: Accepted
- Date: 2026-08-29

## Decision

When a project supports a PHP range, calculate a feature ceiling from the lowest allowed minor and a lifecycle ceiling from the highest known allowed minor. Do not reduce policy to one effective version.

## Consequences

Future generated syntax must remain compatible with the lowest supported minor, while lifecycle warnings can still reflect newer supported minors. PR B will implement this with Composer Semver and fixtures.

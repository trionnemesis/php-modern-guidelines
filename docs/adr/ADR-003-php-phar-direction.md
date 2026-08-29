# ADR-003: PHP and PHAR direction

- Status: Accepted
- Date: 2026-08-29

## Decision

Use PHP 8.2+ and Symfony Console for the CLI. Composer distribution is the first delivery path; a reproducible PHAR is a later direction, not an M0 artifact.

## Consequences

Later policy parsing can use Composer's own Semver semantics. The precise PHAR build tool remains intentionally undecided.

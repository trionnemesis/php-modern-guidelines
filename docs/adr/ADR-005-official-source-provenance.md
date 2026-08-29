# ADR-005: Official-source rule provenance

- Status: Accepted
- Date: 2026-08-29

## Decision

Every PHP language, Core, or bundled-extension rule must link to an official PHP source and record a review date. Third-party analyzer documentation may map verification but cannot establish lifecycle facts.

## Consequences

No PHP factual rules ship in M0. Unverified claims must remain explicitly unverified rather than being inferred from runtime behavior or model memory.

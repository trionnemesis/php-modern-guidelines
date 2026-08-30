# The two-axis policy

`resolve` never reduces a project's PHP compatibility to a single number. It reports two independent
ceilings, and an agent must read both rather than picking one.

- **`feature_ceiling`** is the lowest PHP minor the project must still run on. It bounds which syntax
  and standard-library APIs may be emitted: nothing requiring a newer minor than this may be written,
  no matter how new the development machine's own PHP is.
- **`lifecycle_ceiling`** is the highest PHP minor the declared range is known to allow. It bounds
  which deprecations, removals and behavior changes must be considered: a construct deprecated on any
  minor up to and including this ceiling is a real concern for this project, even if it still works on
  the feature ceiling today.

For `require.php: ^8.2` with this tool's current known coverage, `resolve` reports (excerpted from the
`resolve` golden in `SKILL.md`'s `## Commands` section):

```text
allowed minors       8.2, 8.3, 8.4, 8.5
feature ceiling      8.2
lifecycle ceiling    8.5
coverage             coverage_gap (known 8.2-8.5, open upper bound)
```

`^8.2` allows any future PHP minor, but this tool only knows facts through 8.5, so it reports the gap
rather than inventing lifecycle guidance for versions it has no data on.

## Coverage

Every resolved policy carries a `coverage` object with these keys:

- `coverage.status` — one of `complete`, `coverage_gap`, or `unknown`.
- `coverage.known_min` — the lowest PHP minor this tool has data for.
- `coverage.known_max` — the highest PHP minor this tool has data for.
- `coverage.open_upper_bound` — `true` when the declared constraint allows PHP minors above
  `coverage.known_max` that this tool cannot yet speak to.

A `coverage_gap` status, or any of these warning codes, must be reported to the user rather than
silently assumed away:

- `coverage.below_known_min` — the constraint reaches below the oldest PHP minor this tool knows.
- `coverage.open_upper_bound_bounded` — the constraint's own upper bound is known (for example `^8.2`,
  which Composer resolves to `>=8.2.0 <9.0.0`) but sits above this tool's coverage, so it still reaches
  past known coverage even though it is not unbounded.
- `coverage.open_upper_bound_unbounded` — the constraint has no upper bound at all (for example
  `>=8.2`), so it always reaches past known coverage.

Never widen one of these into the others and never write a wildcard in place of a code: each of the
three is written out in full, verbatim, whenever it is quoted.

## Resolution modes

`--mode` selects one of three resolution strategies:

- **`range-safe`** (the default) keeps `feature_ceiling` and `lifecycle_ceiling` separate, computed
  from the full declared Composer range. This is the two-axis guarantee in its normal form.
- **`single-target`** is a caller-requested collapse to one PHP minor. The schema itself enforces this:
  `allowed_minors` is capped at exactly one item, so `feature_ceiling` and `lifecycle_ceiling` end up
  equal. This mode exists for a caller who has already picked one deployment target; it does not mean
  the two-axis distinction stopped mattering elsewhere.
- **`runtime-observed`** reads the PHP version the CLI itself is currently running on and treats that as
  the target, recording it in `observed_runtime`.

This tool's known PHP coverage is 8.2 through 8.5. A constraint reaching below or above that window
always produces a coverage warning; it never produces an invented answer for a version this tool has no
data on.

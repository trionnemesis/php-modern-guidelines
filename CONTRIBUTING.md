# Contributing

Thank you for considering a contribution. This repository is at M1 (`0.1.0`): the Composer Semver policy resolver, the two-axis rule registry, and the `resolve` / `list-rules` / `explain` commands are implemented. Keep changes aligned with the active milestone and avoid bringing forward later framework-pack, analyzer/adapter, auto-fix, PHAR, or marketplace work into an M1 change.

## Development checks

Use PHP 8.2+ and Composer, then run:

```bash
composer install
composer check
```

`composer check` runs PHPUnit, PHPStan, and the coding-style check.

## Contribution rules

- Keep core commands deterministic and read-only with respect to analyzed projects.
- Do not execute target-project code, load the target project's `vendor/autoload.php`, run Composer scripts/plugins, or add network access to core commands.
- Do not implement Composer constraints with ad-hoc regular expressions; PR B must use Composer Semver.
- Do not add PHP factual rules without an authoritative PHP source URL and a recorded review date. Rephrase documentation rather than copying long PHP manual text.
- Preserve the two-axis policy: feature and lifecycle ceilings are distinct.
- Add or update tests for every behavior change.

See the ADRs in `docs/adr/` for the binding architecture decisions.

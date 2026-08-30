# ADR-007: PHAR build tool and distribution

- Status: Accepted
- Date: 2026-08-30
- Supersedes: the open question in ADR-003 ("The precise PHAR build tool remains intentionally undecided.")
- Amends: ADR-003's "Composer distribution is the first delivery path."

## Decision

Build the distributable PHAR with `humbug/box`, pinned to exactly version `4.7.0`, installed in CI only.
`humbug/box` is not added to this package's `require` or `require-dev`.

The build configuration lives in a committed `box.json.dist`. Continuous integration builds and
smoke-tests the archive on every push and pull request, on the PHP 8.2 floor, with production
dependencies only. The guarded release workflow builds it again and attaches
`php-modern-guidelines.phar` and `php-modern-guidelines.phar.sha256` to the GitHub release.

## Delivery paths as of 0.2.0

ADR-003 recorded that "Composer distribution is the first delivery path". That remains the intent, but it
is not yet realised and this ADR says so rather than leaving the earlier sentence to be read as a shipped
fact: **the package is not registered on Packagist**, so there is no `composer require` install path, and
none is documented in the README, the site, the agent skill or the changelog. The two delivery paths that
exist at 0.2.0 are a git checkout with `composer install`, and this ADR's PHAR attached to the guarded
GitHub release and verified with its `.sha256` file. Publishing to Packagist is a candidate for a later
milestone; until it happens, "Composer distribution" describes how the package is *consumed after a
checkout*, not how it is *distributed*.

## Reproducibility

"Reproducible" here means **content-deterministic within a single build job**, not byte-identical, and not
reproducible across jobs:

- the build tool version, the build PHP version and the `composer install` *flags* are pinned;
- the configuration carries no git-derived and no time-derived value; the archive reports
  `ModernPhpGuidelines\ApplicationFactory::VERSION`, the same constant the release workflow reads;
- the internal PHAR alias is pinned rather than generated;
- CI builds twice in one job and compares the sorted list of archive entries and a SHA-256 hash of each
  entry's contents.

Two properties are deliberately **not** claimed.

**Byte-identity across builds is not claimed.** A PHAR embeds a per-entry modification time and an archive
signature, so two builds of the same tree differ in bytes while carrying identical content.

**Dependency resolution is not pinned across builds.** This package does not commit a `composer.lock`
(`/composer.lock` is in `.gitignore`), so both build steps run `composer install --no-dev` with no lock
present, which Composer treats as an update. The bundled `symfony/console`, `opis/json-schema` and
`composer/semver` versions are therefore resolved fresh at build time, within the ranges
`composer.json` declares, and may differ between the CI job and the release job, and between one release
and the next. The two-builds-in-one-job proof cannot detect this, because both builds in that job share a
single install. Content-determinism is consequently proven *within one job*, over one resolved dependency
set — it is not a claim that a rebuild next month produces the same archive contents. Committing a lock
for the build path was considered and rejected: a library conventionally does not commit one, it is the
repository owner's established convention, and adopting one would also change the meaning of the existing
PHP 8.2–8.5 `checks` job. Pinning dependency resolution is a candidate for a later milestone, not a
property this ADR claims.

## Rejected alternative: box as a Composer dev dependency

`humbug/box:^4.7` resolves against this package with no version conflict, including with the platform PHP
pinned to 8.2.0, so compatibility was not the objection. Footprint was: box pulls roughly one hundred
transitive packages, including the `amphp/parallel` and `react/*` event-loop stacks, `humbug/php-scoper`
with its PhpStorm stub package, and a second JSON Schema implementation alongside the one this package
already depends on. Every contributor would install that on `composer install --dev` for a capability
needed only at release time, which contradicts ADR-002's narrow product boundary. Box's own documentation
recommends a global installation or a bin-plugin over a project-level `require-dev`.

## Consequences

`composer check` stays green on a checkout with no PHAR toolchain installed, and the local development
surface is unchanged. The archive can only be produced where the build tool is available, which in
practice means CI. `box.json.dist` is validated by `box validate` in CI and its internal consistency with
`.gitignore` and both workflows is unit-tested without box being installed.

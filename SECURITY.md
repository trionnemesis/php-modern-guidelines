# Security policy

## Supported versions

Only the current development line, starting at `0.0.1`, is supported while the project is in foundation/alpha status.

## PHP compatibility and runtime security

The package's `composer.json` requirement (`php >=8.2`) declares language/API compatibility,
not a security patch floor. The policy engine reasons about PHP minors (8.2–8.5), deliberately
normalizing away patch versions; a resolved policy is not evidence that a runtime CVE is fixed.
This does not change the existing version policy, schemas, public API, or read-only core.

Operators are responsible for updating the PHP runtime used to execute the CLI or PHAR, including
their container or vendor packages. The PHAR packages this tool's PHP code, not a PHP interpreter.
`doctor` checks bounded metadata and this tool's installation; `verify` explicitly runs a
preinstalled PHPCompatibility analyzer for advisory code-compatibility evidence. Neither command
scans for runtime CVEs, establishes a security patch level, or patches PHP. See
[ADR-006](docs/adr/ADR-006-read-only-core.md) and
[ADR-008](docs/adr/ADR-008-external-verification-adapters.md).

### PHP2Shell: CVE-2026-109618 and CVE-2026-109619

The [PHP security notice of 2026-10-21](https://www.php.net/archive/2026.php#2026-10-21-5)
identifies these upstream fixed floors, each within its own branch:

| PHP branch | Upstream fixed floor for these two CVEs |
| --- | --- |
| 8.2 | 8.2.35 |
| 8.3 | 8.3.36 |
| 8.4 | 8.4.27 |
| 8.5 | 8.5.12 |

Use a maintained, patched release or documented vendor backport. A bare version comparison does
not establish a distribution package's backport status. The upstream advisories are
[OOM corruption](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) and
[stale global state](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp).

The notice concerns PHP serving web requests. Static source review has not identified a directly
exploitable PHP2Shell path in this project's default CLI/PHAR usage; this is not a guarantee for
downstream integrations. GitHub Pages serves static files. User-operated Web SAPI or web-worker
embeddings and their runtime/configuration are not verified by this repository's CI.

Record the actual runtime's `PHP_VERSION`, package/backport provenance where applicable, commit SHA,
execution time and CI job URL when verifying a build. CI success establishes only the checks and
runtime actually recorded for that job; it does not attest downstream deployments or a different
Web SAPI. See [Issue #27](https://github.com/trionnemesis/php-modern-guidelines/issues/27) for
verification evidence and remaining unknowns. CI predating the notice is not post-fix evidence.

### Separate tracking: CVE-2026-115560

As checked on 2026-11-01, the [PHP informational advisory of 2026-10-23](https://www.php.net/archive/2026.php#2026-10-23-1)
tracks this separately pending a regular-release fix. The floors above do not establish its remediation.
The described path requires session upload progress plus a configured `php_binary` or `php_serialize`
handler switched to `php` at runtime before `session_start()`. PHP recommends
`session.upload_progress.enabled=0` until the fix is released. Track its patch and configuration
evidence independently; a passing compatibility test is not remediation evidence.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Report it privately to the repository maintainer through GitHub's private vulnerability-reporting feature when it is enabled, or contact the maintainer through the repository profile.

Include a minimal reproduction, affected version, impact, and any relevant trust-boundary detail. The project aims for a read-only core: it must not execute analyzed project code, load the analyzed project's `vendor/autoload.php`, or run Composer scripts or plugins. Reports that challenge that boundary are particularly valuable.

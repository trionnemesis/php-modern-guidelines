# Security policy

## Supported versions

Only the current development line, starting at `0.0.1`, is supported while the project is in foundation/alpha status.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Report it privately to the repository maintainer through GitHub's private vulnerability-reporting feature when it is enabled, or contact the maintainer through the repository profile.

Include a minimal reproduction, affected version, impact, and any relevant trust-boundary detail. The project aims for a read-only core: it must not execute analyzed project code, load the analyzed project's `vendor/autoload.php`, or run Composer scripts or plugins. Reports that challenge that boundary are particularly valuable.

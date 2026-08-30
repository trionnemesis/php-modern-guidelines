<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Diagnostics;

/**
 * The status of one `doctor` check. Ordering for `DiagnosticReport::status()` (worst first) is
 * `Fail` > `Warn` > `Skipped` > `Ok`; a report where everything that ran passed but something could
 * not run at all is therefore not reported as clean.
 */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skipped = 'skipped';
}

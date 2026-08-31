<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

/** Stable role used instead of leaking the checkout-specific absolute project root. */
enum InvocationWorkingDirectory: string
{
    case ProjectRoot = 'project_root';
}

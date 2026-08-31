<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

/** Stable, secret-free child-process environment policy. */
enum InvocationEnvironment: string
{
    case Sanitized = 'sanitized';
}

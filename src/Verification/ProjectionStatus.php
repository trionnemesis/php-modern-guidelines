<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

enum ProjectionStatus: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case NotEvaluated = 'not_evaluated';
}

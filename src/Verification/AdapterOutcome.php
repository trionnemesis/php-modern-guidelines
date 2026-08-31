<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

enum AdapterOutcome: string
{
    case Completed = 'completed';
    case Unavailable = 'unavailable';
    case Failed = 'failed';
    case UnsupportedPolicy = 'unsupported_policy';
}

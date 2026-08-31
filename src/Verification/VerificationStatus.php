<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

enum VerificationStatus: string
{
    case Success = 'success';
    case Findings = 'findings';
    case Unavailable = 'unavailable';
    case Failed = 'failed';
    case UnsupportedPolicy = 'unsupported_policy';
}

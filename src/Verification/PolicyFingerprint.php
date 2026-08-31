<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Support\JsonPrinter;

final class PolicyFingerprint
{
    public static function forPolicy(ResolvedPolicy $policy): string
    {
        $semanticPolicy = $policy->toArray();
        unset($semanticPolicy['project_root']);

        return 'sha256:' . hash('sha256', JsonPrinter::encode($semanticPolicy));
    }
}

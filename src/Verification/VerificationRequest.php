<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Policy\ResolvedPolicy;

final class VerificationRequest
{
    public function __construct(
        public readonly ResolvedPolicy $policy,
        public readonly string $executable,
    ) {
        if (!ExecutableEvidenceNormalizer::isValidSelection($this->executable)) {
            throw new \LogicException(
                'The selected verification executable must be a stable path or PATH name and must not use a reserved identity.',
            );
        }
    }

    public function evidenceExecutable(): string
    {
        return ExecutableEvidenceNormalizer::normalize($this->executable, $this->policy->projectRoot);
    }
}

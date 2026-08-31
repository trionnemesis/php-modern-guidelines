<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Support;

use ModernPhpGuidelines\Verification\AdapterOutcome;
use ModernPhpGuidelines\Verification\AdapterPlan;
use ModernPhpGuidelines\Verification\AdapterResult;
use ModernPhpGuidelines\Verification\PlannedVerificationInvocation;
use ModernPhpGuidelines\Verification\ProjectionStatus;
use ModernPhpGuidelines\Verification\VerificationAdapter;
use ModernPhpGuidelines\Verification\VerificationInvocation;
use ModernPhpGuidelines\Verification\VerificationRequest;

/** Deterministic test double. It is dev-autoloaded and never bundled into the PHAR. */
final class FakeVerificationAdapter implements VerificationAdapter
{
    private int $verificationCallCount = 0;

    public function __construct(
        private readonly string $adapterId,
        private readonly AdapterResult $result,
        private readonly ?AdapterPlan $explicitPlan = null,
    ) {}

    public function id(): string
    {
        return $this->adapterId;
    }

    public function plan(VerificationRequest $request): AdapterPlan
    {
        if ($this->explicitPlan !== null) {
            return $this->explicitPlan;
        }

        return match ($this->result->outcome) {
            AdapterOutcome::Completed, AdapterOutcome::Failed => new AdapterPlan(
                ProjectionStatus::Supported,
                array_map(
                    static fn(VerificationInvocation $invocation): PlannedVerificationInvocation =>
                        PlannedVerificationInvocation::fromExecuted($invocation),
                    $this->result->invocations,
                ),
                null,
            ),
            AdapterOutcome::Unavailable => new AdapterPlan(
                ProjectionStatus::NotEvaluated,
                [],
                $this->result->reason,
            ),
            AdapterOutcome::UnsupportedPolicy => new AdapterPlan(
                ProjectionStatus::Unsupported,
                [],
                $this->result->reason,
            ),
        };
    }

    public function verify(VerificationRequest $request, AdapterPlan $plan): AdapterResult
    {
        ++$this->verificationCallCount;

        return $this->result;
    }

    public function verificationCallCount(): int
    {
        return $this->verificationCallCount;
    }
}

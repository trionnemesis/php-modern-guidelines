<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification\Adapter;

use ModernPhpGuidelines\Verification\AdapterPlan;
use ModernPhpGuidelines\Verification\AdapterResult;
use ModernPhpGuidelines\Verification\ExecutableLocator;
use ModernPhpGuidelines\Verification\ProjectionStatus;
use ModernPhpGuidelines\Verification\VerificationAdapter;
use ModernPhpGuidelines\Verification\VerificationReason;
use ModernPhpGuidelines\Verification\VerificationRequest;

/**
 * M3-A's truthful production placeholder. It proves the public boundary without parsing or running
 * a real analyzer; M3-B replaces this registration with the PHPCompatibility adapter.
 */
final class UnavailableAdapter implements VerificationAdapter
{
    public function __construct(
        private readonly string $adapterId,
        private readonly ExecutableLocator $locator,
    ) {}

    public function id(): string
    {
        return $this->adapterId;
    }

    public function plan(VerificationRequest $request): AdapterPlan
    {
        if ($this->locator->locate($request->executable, $request->policy->projectRoot) === null) {
            return new AdapterPlan(
                ProjectionStatus::NotEvaluated,
                [],
                new VerificationReason(
                    VerificationReason::EXECUTABLE_UNAVAILABLE,
                    'The selected executable is not available or executable.',
                ),
            );
        }

        return new AdapterPlan(
            ProjectionStatus::NotEvaluated,
            [],
            new VerificationReason(
                VerificationReason::CAPABILITY_UNAVAILABLE,
                sprintf('The %s verification adapter is not implemented in this build.', $this->adapterId),
            ),
        );
    }

    public function verify(VerificationRequest $request, AdapterPlan $plan): AdapterResult
    {
        throw new \LogicException('The M3-A unavailable adapter has no executable verification phase.');
    }
}

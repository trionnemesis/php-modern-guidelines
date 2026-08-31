<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Rule\ApplicabilityEvaluator;
use ModernPhpGuidelines\Rule\RuleRegistry;

final class VerificationOrchestrator
{
    public function __construct(
        private readonly VerificationAdapterRegistry $adapters,
        private readonly ApplicabilityEvaluator $evaluator,
    ) {}

    public function run(
        string $adapterId,
        VerificationRequest $request,
        RuleRegistry $rules,
    ): VerificationReport {
        $adapter = $this->adapters->get($adapterId);
        $plan = $adapter->plan($request);
        (new PolicyProjectionValidator())->assertExact($request, $plan);

        $result = match ($plan->projectionStatus) {
            ProjectionStatus::NotEvaluated => new AdapterResult(
                AdapterOutcome::Unavailable,
                ProjectionStatus::NotEvaluated,
                null,
                [],
                [],
                $plan->reason,
            ),
            ProjectionStatus::Unsupported => new AdapterResult(
                AdapterOutcome::UnsupportedPolicy,
                ProjectionStatus::Unsupported,
                null,
                [],
                [],
                $plan->reason,
            ),
            ProjectionStatus::Supported => $adapter->verify($request, $plan),
        };

        if ($plan->projectionStatus === ProjectionStatus::Supported
            && $result->outcome !== AdapterOutcome::Completed
            && $result->outcome !== AdapterOutcome::Failed
            && $result->outcome !== AdapterOutcome::Unavailable) {
            throw new \LogicException('A supported adapter plan must execute to a completed, failed, or unavailable result.');
        }

        return VerificationReport::fromAdapterResult(
            $adapterId,
            $request,
            $plan,
            $result,
            $rules,
            $this->evaluator,
        );
    }
}

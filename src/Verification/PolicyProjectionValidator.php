<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Policy\CoverageStatus;
use ModernPhpGuidelines\Policy\ResolutionMode;

/** Enforces exact, deterministic policy projection before an adapter may execute a process. */
final class PolicyProjectionValidator
{
    public function assertExact(VerificationRequest $request, AdapterPlan $plan): void
    {
        if ($plan->projectionStatus !== ProjectionStatus::Supported) {
            return;
        }

        $policy = $request->policy;
        if ($policy->mode === ResolutionMode::RangeSafe
            && ($policy->coverage->status !== CoverageStatus::Complete || $policy->coverage->openUpperBound)) {
            throw new \LogicException(
                'Verification adapters cannot plan exact range-safe projection when policy coverage is incomplete.',
            );
        }

        $seen = [];
        foreach ($plan->invocations as $invocation) {
            if ($invocation->executable !== $request->evidenceExecutable()) {
                throw new \LogicException(sprintf(
                    'Planned verification invocation "%s" did not preserve the normalized executable identity.',
                    $invocation->id,
                ));
            }

            $this->assertStableInvocationArguments($invocation);

            if ($invocation->purpose !== InvocationPurpose::Analysis) {
                continue;
            }

            foreach ($invocation->policyMinors as $minor) {
                if (isset($seen[$minor])) {
                    throw new \LogicException(sprintf(
                        'Planned verification policy projection overlaps on PHP %s.',
                        $minor,
                    ));
                }
                $seen[$minor] = true;
            }
        }

        $projected = array_keys($seen);
        if ($projected !== $policy->allowedMinors) {
            throw new \LogicException(sprintf(
                'Planned verification policy projection (%s) does not exactly match the resolved policy (%s).',
                implode(', ', $projected),
                implode(', ', $policy->allowedMinors),
            ));
        }
    }

    private function assertStableInvocationArguments(PlannedVerificationInvocation $invocation): void
    {
        foreach ($invocation->arguments as $argument) {
            $normalizedArgument = str_replace('\\', '/', $argument);
            $containsAbsoluteOperand = preg_match(
                '~(?:^|[^A-Za-z0-9._-])(?:/|[A-Za-z]:/)~',
                $normalizedArgument,
            ) === 1;
            $containsParentTraversal = preg_match(
                '~(?:^|[^A-Za-z0-9._-])\.\.(?:/|$)~',
                $normalizedArgument,
            ) === 1;
            $containsDriveRelativeOperand = preg_match(
                '~(?:^|[^A-Za-z0-9._-])[A-Za-z]:(?!/)~',
                $normalizedArgument,
            ) === 1;

            if ($containsAbsoluteOperand || $containsParentTraversal || $containsDriveRelativeOperand) {
                throw new \LogicException(sprintf(
                    'Planned verification invocation "%s" arguments must use project-relative, cwd-based paths.',
                    $invocation->id,
                ));
            }
        }
    }

}

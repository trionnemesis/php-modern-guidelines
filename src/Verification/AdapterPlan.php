<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

/** Immutable adapter plan validated before any external process is started. */
final class AdapterPlan
{
    /**
     * @param list<PlannedVerificationInvocation> $invocations
     */
    public function __construct(
        public readonly ProjectionStatus $projectionStatus,
        array $invocations,
        public readonly ?VerificationReason $reason,
    ) {
        $sortedInvocations = $invocations;
        usort(
            $sortedInvocations,
            static fn(PlannedVerificationInvocation $left, PlannedVerificationInvocation $right): int => strnatcmp(
                $left->id,
                $right->id,
            ),
        );

        $ids = array_map(
            static fn(PlannedVerificationInvocation $invocation): string => $invocation->id,
            $sortedInvocations,
        );
        if (array_values(array_unique($ids)) !== $ids) {
            throw new \LogicException('Planned verification invocation ids must be unique.');
        }

        $this->invocations = $sortedInvocations;
        $this->assertInvariants();
    }

    /** @var list<PlannedVerificationInvocation> */
    public readonly array $invocations;

    private function assertInvariants(): void
    {
        if ($this->projectionStatus === ProjectionStatus::Supported) {
            if ($this->invocations === []) {
                throw new \LogicException('A supported adapter plan must contain at least one invocation.');
            }
            if ($this->reason !== null) {
                throw new \LogicException('A supported adapter plan must not carry a reason.');
            }

            return;
        }

        if ($this->invocations !== []) {
            throw new \LogicException('A non-supported adapter plan must not contain invocations.');
        }
        if ($this->reason === null) {
            throw new \LogicException('A non-supported adapter plan must carry a canonical reason.');
        }

        if ($this->projectionStatus === ProjectionStatus::NotEvaluated) {
            if (!in_array($this->reason->code, VerificationReason::unavailableCodes(), true)) {
                throw new \LogicException('A not-evaluated adapter plan must use a canonical unavailable reason.');
            }

            return;
        }

        if ($this->reason->code !== VerificationReason::POLICY_PROJECTION_UNSUPPORTED) {
            throw new \LogicException('An unsupported adapter plan must use the canonical projection reason.');
        }
    }
}

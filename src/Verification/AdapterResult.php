<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Verification\Process\ProcessState;

final class AdapterResult
{
    /**
     * @param list<VerificationInvocation> $invocations
     * @param list<VerificationFinding>    $findings
     */
    public function __construct(
        public readonly AdapterOutcome $outcome,
        public readonly ProjectionStatus $projectionStatus,
        public readonly ?string $toolVersion,
        array $invocations,
        array $findings,
        public readonly ?VerificationReason $reason,
    ) {
        $sortedInvocations = $invocations;
        usort(
            $sortedInvocations,
            static fn(VerificationInvocation $a, VerificationInvocation $b): int => strnatcmp($a->id, $b->id),
        );
        $this->invocations = $sortedInvocations;

        $sortedFindings = $findings;
        usort(
            $sortedFindings,
            static fn(VerificationFinding $a, VerificationFinding $b): int => $a->sortKey() <=> $b->sortKey(),
        );
        for ($index = 1; $index < count($sortedFindings); ++$index) {
            if ($sortedFindings[$index - 1]->sortKey() === $sortedFindings[$index]->sortKey()) {
                throw new \LogicException('Verification findings must be unique.');
            }
        }
        $this->findings = $sortedFindings;

        $this->assertInvariants();
    }

    /** @var list<VerificationInvocation> */
    public readonly array $invocations;

    /** @var list<VerificationFinding> */
    public readonly array $findings;

    private function assertInvariants(): void
    {
        if ($this->toolVersion === ''
            || ($this->toolVersion !== null && preg_match('/[\x00-\x1F\x7F]/', $this->toolVersion) === 1)) {
            throw new \LogicException('A verification tool version must be non-empty and free of control characters when present.');
        }

        $ids = array_map(static fn(VerificationInvocation $invocation): string => $invocation->id, $this->invocations);
        if (array_values(array_unique($ids)) !== $ids) {
            throw new \LogicException('Verification invocation ids must be unique.');
        }

        foreach ($this->findings as $finding) {
            foreach ($finding->invocationIds as $invocationId) {
                if (!in_array($invocationId, $ids, true)) {
                    throw new \LogicException(sprintf(
                        'Finding "%s" references unknown invocation "%s".',
                        $finding->externalRuleId,
                        $invocationId,
                    ));
                }

                foreach ($this->invocations as $invocation) {
                    if ($invocation->id === $invocationId
                        && $invocation->purpose !== InvocationPurpose::Analysis) {
                        throw new \LogicException(sprintf(
                            'Finding "%s" cannot reference tool-probe invocation "%s".',
                            $finding->externalRuleId,
                            $invocationId,
                        ));
                    }
                }
            }
        }

        if ($this->outcome === AdapterOutcome::Completed) {
            if ($this->projectionStatus !== ProjectionStatus::Supported) {
                throw new \LogicException('A completed adapter result must have a supported policy projection.');
            }
            if ($this->invocations === []) {
                throw new \LogicException('A completed adapter result must record at least one invocation.');
            }
            if ($this->reason !== null) {
                throw new \LogicException('A completed adapter result must not carry a failure reason.');
            }
            if ($this->toolVersion === null) {
                throw new \LogicException('A completed adapter result must preserve the detected tool version.');
            }
            foreach ($this->invocations as $invocation) {
                if ($invocation->status !== ProcessState::Exited) {
                    throw new \LogicException('A completed adapter result may contain only exited invocations.');
                }
                if ($this->findings === [] && $invocation->exitCode !== 0) {
                    throw new \LogicException('A no-finding completed result may contain only zero-exit invocations.');
                }
            }

            return;
        }

        if ($this->findings !== []) {
            throw new \LogicException('A non-completed adapter result must not publish partial findings.');
        }
        if ($this->reason === null) {
            throw new \LogicException('A non-completed adapter result must carry a stable reason.');
        }

        if ($this->outcome === AdapterOutcome::Unavailable) {
            if (!in_array($this->reason->code, VerificationReason::unavailableCodes(), true)) {
                throw new \LogicException('An unavailable adapter result must use a canonical unavailable reason.');
            }

            if ($this->projectionStatus === ProjectionStatus::NotEvaluated) {
                if ($this->invocations !== [] || $this->toolVersion !== null) {
                    throw new \LogicException(
                        'A not-evaluated unavailable result cannot claim an invocation or detected tool version.',
                    );
                }

                return;
            }

            if ($this->projectionStatus !== ProjectionStatus::Supported
                || $this->invocations === []
                || $this->reason->code !== VerificationReason::CAPABILITY_UNAVAILABLE) {
                throw new \LogicException(
                    'An unavailable result after projection must preserve an attempted capability-check invocation.',
                );
            }
            foreach ($this->invocations as $invocation) {
                if ($invocation->status !== ProcessState::Exited) {
                    throw new \LogicException(
                        'Capability unavailability may be reported only after normally exited invocations.',
                    );
                }
            }

            return;
        }

        if ($this->outcome === AdapterOutcome::UnsupportedPolicy) {
            if ($this->projectionStatus !== ProjectionStatus::Unsupported || $this->invocations !== []) {
                throw new \LogicException('An unsupported-policy result must not invoke the external tool.');
            }
            if ($this->toolVersion !== null) {
                throw new \LogicException('An unsupported-policy result cannot claim a detected tool version.');
            }
            if ($this->reason->code !== VerificationReason::POLICY_PROJECTION_UNSUPPORTED) {
                throw new \LogicException('An unsupported-policy result must use the canonical projection reason.');
            }

            return;
        }

        if ($this->projectionStatus !== ProjectionStatus::Supported) {
            throw new \LogicException('A failed adapter result must have completed exact policy projection first.');
        }
        if ($this->invocations === []) {
            throw new \LogicException('A failed adapter result must preserve at least one attempted invocation.');
        }
        if (!in_array($this->reason->code, VerificationReason::failureCodes(), true)) {
            throw new \LogicException('A failed adapter result must use a canonical failure reason.');
        }

        $matchingInvocation = false;
        foreach ($this->invocations as $invocation) {
            $matchingInvocation = match ($this->reason->code) {
                VerificationReason::PROCESS_START_FAILED => $invocation->status === ProcessState::StartFailed,
                VerificationReason::PROCESS_TIMED_OUT => $invocation->status === ProcessState::TimedOut,
                VerificationReason::PROCESS_EXIT_FAILED => $invocation->status === ProcessState::Exited
                    && $invocation->exitCode !== null
                    && $invocation->exitCode !== 0,
                VerificationReason::PROCESS_SIGNALED => $invocation->status === ProcessState::Signaled,
                VerificationReason::OUTPUT_LIMIT_EXCEEDED => $invocation->status === ProcessState::OutputLimitExceeded,
                VerificationReason::OUTPUT_INVALID => $invocation->status === ProcessState::Exited,
                default => false,
            };
            if ($matchingInvocation) {
                break;
            }
        }
        if (!$matchingInvocation) {
            throw new \LogicException('A failed adapter reason must match at least one recorded invocation state.');
        }
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Exception\RuleDataException;
use ModernPhpGuidelines\Rule\ApplicabilityEvaluator;
use ModernPhpGuidelines\Rule\RuleRegistry;

final class VerificationReport
{
    public const OUTPUT_VERSION = '1.0.0';

    /**
     * @param list<VerificationInvocation> $invocations
     * @param array{
     *     invocation_count: int,
     *     finding_count: int,
     *     mapped_finding_count: int,
     *     unmapped_finding_count: int,
     *     mapping_count: int,
     *     mapped_rule_count: int,
     * } $summary
     * @param list<RuleContext>         $ruleContexts
     * @param list<VerificationFinding> $findings
     */
    private function __construct(
        public readonly VerificationStatus $status,
        public readonly int $exitCode,
        public readonly string $adapterId,
        public readonly string $executable,
        public readonly ?string $toolVersion,
        public readonly VerificationRequest $request,
        public readonly AdapterPlan $plan,
        public readonly ProjectionStatus $projectionStatus,
        public readonly array $invocations,
        public readonly array $summary,
        public readonly ?VerificationReason $reason,
        public readonly array $ruleContexts,
        public readonly array $findings,
    ) {}

    /** @throws RuleDataException when an adapter claims an unknown internal rule mapping. */
    public static function fromAdapterResult(
        string $adapterId,
        VerificationRequest $request,
        AdapterPlan $plan,
        AdapterResult $result,
        RuleRegistry $rules,
        ApplicabilityEvaluator $evaluator,
    ): self {
        self::assertExecutionMatchesPlan($plan, $result);

        $mappedFindingCount = 0;
        $unmappedFindingCount = 0;
        $mappingCount = 0;
        $mappedRuleIds = [];

        foreach ($result->findings as $finding) {
            if ($finding->mappingStatus === MappingStatus::Mapped) {
                ++$mappedFindingCount;
                $mappingCount += count($finding->mappedRuleIds);
                array_push($mappedRuleIds, ...$finding->mappedRuleIds);
            } else {
                ++$unmappedFindingCount;
            }
        }

        $mappedRuleIds = array_values(array_unique($mappedRuleIds));
        sort($mappedRuleIds, SORT_STRING);

        $ruleContexts = [];
        foreach ($mappedRuleIds as $ruleId) {
            if (!$rules->has($ruleId)) {
                throw new RuleDataException(sprintf(
                    'Verification adapter "%s" mapped evidence to unknown internal rule "%s".',
                    $adapterId,
                    $ruleId,
                ));
            }

            $rule = $rules->get($ruleId);
            $ruleContexts[] = new RuleContext($rule, $evaluator->evaluate($rule, $request->policy));
        }

        [$status, $exitCode] = self::statusAndExitCode($result);

        $summary = [
            'invocation_count' => count($result->invocations),
            'finding_count' => count($result->findings),
            'mapped_finding_count' => $mappedFindingCount,
            'unmapped_finding_count' => $unmappedFindingCount,
            'mapping_count' => $mappingCount,
            'mapped_rule_count' => count($mappedRuleIds),
        ];

        return new self(
            $status,
            $exitCode,
            $adapterId,
            $request->evidenceExecutable(),
            $result->toolVersion,
            $request,
            $plan,
            $result->projectionStatus,
            $result->invocations,
            $summary,
            $result->reason,
            $ruleContexts,
            $result->findings,
        );
    }

    /** @return array<string, mixed> canonical verification.schema.json key order */
    public function toArray(): array
    {
        $policy = $this->request->policy;

        return [
            'output_version' => self::OUTPUT_VERSION,
            'status' => $this->status->value,
            'exit_code' => $this->exitCode,
            'adapter' => [
                'id' => $this->adapterId,
                'executable' => $this->executable,
                'tool_version' => $this->toolVersion,
            ],
            'policy' => [
                'fingerprint' => PolicyFingerprint::forPolicy($policy),
                'mode' => $policy->mode->value,
                'allowed_minors' => $policy->allowedMinors,
                'feature_ceiling' => $policy->featureCeiling,
                'lifecycle_ceiling' => $policy->lifecycleCeiling,
                'projection_status' => $this->projectionStatus->value,
                'planned_invocations' => array_map(
                    static fn(PlannedVerificationInvocation $invocation): array => $invocation->toArray(),
                    $this->plan->invocations,
                ),
            ],
            'invocations' => array_map(
                static fn(VerificationInvocation $invocation): array => $invocation->toArray(),
                $this->invocations,
            ),
            'summary' => $this->summary,
            'reason' => $this->reason?->toArray(),
            'rule_contexts' => array_map(
                static fn(RuleContext $context): array => $context->toArray(),
                $this->ruleContexts,
            ),
            'findings' => array_map(
                static fn(VerificationFinding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }

    /** @return array{VerificationStatus, int} */
    private static function statusAndExitCode(AdapterResult $result): array
    {
        return match ($result->outcome) {
            AdapterOutcome::Completed => $result->findings === []
                ? [VerificationStatus::Success, ExitCode::SUCCESS]
                : [VerificationStatus::Findings, ExitCode::VERIFICATION_FINDINGS],
            AdapterOutcome::Unavailable => [VerificationStatus::Unavailable, ExitCode::ADAPTER_UNAVAILABLE],
            AdapterOutcome::Failed => [VerificationStatus::Failed, ExitCode::ADAPTER_FAILED],
            AdapterOutcome::UnsupportedPolicy => [
                VerificationStatus::UnsupportedPolicy,
                ExitCode::POLICY_PROJECTION_UNSUPPORTED,
            ],
        };
    }

    private static function assertExecutionMatchesPlan(AdapterPlan $plan, AdapterResult $result): void
    {
        if ($result->projectionStatus !== $plan->projectionStatus) {
            throw new \LogicException('The adapter execution result changed the prevalidated projection status.');
        }

        $plannedById = [];
        foreach ($plan->invocations as $planned) {
            $plannedById[$planned->id] = $planned;
        }

        foreach ($result->invocations as $invocation) {
            $planned = $plannedById[$invocation->id] ?? null;
            if ($planned === null || !$planned->matchesExecuted($invocation)) {
                throw new \LogicException(sprintf(
                    'Verification invocation "%s" does not match the prevalidated projection plan.',
                    $invocation->id,
                ));
            }
        }

        if ($result->outcome === AdapterOutcome::Completed
            && count($result->invocations) !== count($plan->invocations)) {
            throw new \LogicException('A completed adapter result must execute every planned invocation.');
        }
    }
}

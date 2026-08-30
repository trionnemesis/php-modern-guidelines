<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

use ModernPhpGuidelines\Php\KnownPhpMinors;

/**
 * Immutable resolved policy value object. Consumed by slices C and D.
 */
final class ResolvedPolicy
{
    public const SCHEMA_VERSION = '1.0.0';

    /**
     * @param list<string>       $allowedMinors ascending, non-empty
     * @param list<PolicySource> $sources       non-empty, precedence order
     * @param list<string>       $warnings      catalogue order
     */
    public function __construct(
        public readonly ResolutionMode $mode,
        public readonly string $projectRoot,
        public readonly ?string $declaredConstraint,
        public readonly array $allowedMinors,
        public readonly string $featureCeiling,
        public readonly string $lifecycleCeiling,
        public readonly ?string $platformOverride,
        public readonly ?string $observedRuntime,
        public readonly Coverage $coverage,
        public readonly Confidence $confidence,
        public readonly array $sources,
        public readonly array $warnings,
    ) {
        if ($this->allowedMinors === []) {
            throw new \LogicException('ResolvedPolicy::$allowedMinors must not be empty.');
        }

        if ($this->sources === []) {
            throw new \LogicException('ResolvedPolicy::$sources must not be empty.');
        }

        $lowest = $this->allowedMinors[0];
        $highest = $this->allowedMinors[0];
        foreach ($this->allowedMinors as $minor) {
            $lowest = KnownPhpMinors::lowest($lowest, $minor);
            $highest = KnownPhpMinors::highest($highest, $minor);
        }

        if ($this->featureCeiling !== $lowest) {
            throw new \LogicException('ResolvedPolicy::$featureCeiling must equal min($allowedMinors).');
        }

        if ($this->lifecycleCeiling !== $highest) {
            throw new \LogicException('ResolvedPolicy::$lifecycleCeiling must equal max($allowedMinors).');
        }

        if ($this->mode === ResolutionMode::SingleTarget && count($this->allowedMinors) !== 1) {
            throw new \LogicException('ResolvedPolicy::$allowedMinors must contain exactly one minor in single-target mode.');
        }
    }

    /** `in_array($minor, $this->allowedMinors, true)`. Consumer: §4.4's `--minor` membership check. */
    public function allows(string $minor): bool
    {
        return in_array($minor, $this->allowedMinors, true);
    }

    /**
     * @return array{
     *     schema_version: string,
     *     mode: string,
     *     project_root: string,
     *     declared_constraint: string|null,
     *     allowed_minors: list<string>,
     *     feature_ceiling: string,
     *     lifecycle_ceiling: string,
     *     platform_override: string|null,
     *     observed_runtime: string|null,
     *     coverage: array{status: string, known_min: string, known_max: string, open_upper_bound: bool},
     *     confidence: string,
     *     sources: list<array{type: string, path: string|null, value: string|null}>,
     *     warnings: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $this->mode->value,
            'project_root' => $this->projectRoot,
            'declared_constraint' => $this->declaredConstraint,
            'allowed_minors' => $this->allowedMinors,
            'feature_ceiling' => $this->featureCeiling,
            'lifecycle_ceiling' => $this->lifecycleCeiling,
            'platform_override' => $this->platformOverride,
            'observed_runtime' => $this->observedRuntime,
            'coverage' => $this->coverage->toArray(),
            'confidence' => $this->confidence->value,
            'sources' => array_map(static fn(PolicySource $source): array => $source->toArray(), $this->sources),
            'warnings' => $this->warnings,
        ];
    }
}

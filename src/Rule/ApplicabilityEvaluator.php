<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

use ModernPhpGuidelines\Php\KnownPhpMinors;
use ModernPhpGuidelines\Policy\ResolvedPolicy;

/**
 * Pure `(Rule, ResolvedPolicy) -> ApplicabilityResult`, over both policy axes (ADR-004). It reads
 * nothing else — no `PHP_VERSION`, no filesystem, no clock.
 */
final class ApplicabilityEvaluator
{
    public function evaluate(Rule $rule, ResolvedPolicy $policy): ApplicabilityResult
    {
        $introducedIn = $rule->introducedIn;
        $deprecatedIn = $rule->deprecatedIn;
        $removedIn = $rule->removedIn;
        $featureCeiling = $policy->featureCeiling;
        $lifecycleCeiling = $policy->lifecycleCeiling;
        $allowedMinors = $policy->allowedMinors;

        if ($removedIn !== null && KnownPhpMinors::compare($removedIn, $featureCeiling) <= 0) {
            return new ApplicabilityResult(
                ApplicabilityStatus::RemovedAcrossRange,
                ApplicabilityAxis::Lifecycle,
                self::atOrAfter($removedIn, $allowedMinors),
            );
        }

        if ($removedIn !== null && KnownPhpMinors::compare($removedIn, $lifecycleCeiling) <= 0) {
            return new ApplicabilityResult(
                ApplicabilityStatus::RemovedInRange,
                ApplicabilityAxis::Lifecycle,
                self::atOrAfter($removedIn, $allowedMinors),
            );
        }

        if ($deprecatedIn !== null && KnownPhpMinors::compare($deprecatedIn, $featureCeiling) <= 0) {
            return new ApplicabilityResult(
                ApplicabilityStatus::DeprecatedAcrossRange,
                ApplicabilityAxis::Lifecycle,
                self::atOrAfter($deprecatedIn, $allowedMinors),
            );
        }

        if ($deprecatedIn !== null && KnownPhpMinors::compare($deprecatedIn, $lifecycleCeiling) <= 0) {
            return new ApplicabilityResult(
                ApplicabilityStatus::DeprecatedInRange,
                ApplicabilityAxis::Lifecycle,
                self::atOrAfter($deprecatedIn, $allowedMinors),
            );
        }

        if ($introducedIn !== null && KnownPhpMinors::compare($introducedIn, $lifecycleCeiling) > 0) {
            return new ApplicabilityResult(
                ApplicabilityStatus::NotInRange,
                ApplicabilityAxis::Feature,
                self::atOrAfter($introducedIn, $allowedMinors),
            );
        }

        if ($introducedIn !== null && KnownPhpMinors::compare($introducedIn, $featureCeiling) > 0) {
            return new ApplicabilityResult(
                ApplicabilityStatus::ForbiddenAboveFeatureCeiling,
                ApplicabilityAxis::Feature,
                self::atOrAfter($introducedIn, $allowedMinors),
            );
        }

        return new ApplicabilityResult(
            ApplicabilityStatus::Applicable,
            ApplicabilityAxis::None,
            $allowedMinors,
        );
    }

    /**
     * @param  list<string> $allowedMinors ascending
     * @return list<string> ascending subset of $allowedMinors, each >= $threshold
     */
    private static function atOrAfter(string $threshold, array $allowedMinors): array
    {
        return array_values(array_filter(
            $allowedMinors,
            static fn(string $minor): bool => KnownPhpMinors::compare($threshold, $minor) <= 0,
        ));
    }
}

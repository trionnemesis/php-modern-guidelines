<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Rule;

use ModernPhpGuidelines\Policy\Confidence;
use ModernPhpGuidelines\Policy\Coverage;
use ModernPhpGuidelines\Policy\CoverageStatus;
use ModernPhpGuidelines\Policy\PolicySource;
use ModernPhpGuidelines\Policy\ResolutionMode;
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Policy\SourceType;
use ModernPhpGuidelines\Rule\BehaviorChangeRisk;
use ModernPhpGuidelines\Rule\Rule;
use ModernPhpGuidelines\Rule\RuleCategory;
use ModernPhpGuidelines\Rule\RuleExample;
use ModernPhpGuidelines\Rule\RuleKind;
use ModernPhpGuidelines\Rule\RulePriority;
use ModernPhpGuidelines\Rule\RuleSource;
use ModernPhpGuidelines\Rule\RuleVerification;

/**
 * Minimal, hand-built `Rule` and `ResolvedPolicy` factories shared by the slice-C unit tests. These
 * are test-only helpers, never loaded from disk, so they may skip the schema/JSON round-trip entirely.
 */
trait RuleFixtures
{
    private static function makeRule(
        string $id,
        RuleKind $kind,
        ?string $introducedIn,
        ?string $deprecatedIn,
        ?string $removedIn,
    ): Rule {
        $category = RuleCategory::from(explode('.', $id)[0]);

        return new Rule(
            id: $id,
            title: 'Fixture: ' . $id,
            summary: 'Synthetic fixture rule.',
            category: $category,
            kind: $kind,
            priority: RulePriority::P2,
            introducedIn: $introducedIn,
            deprecatedIn: $deprecatedIn,
            removedIn: $removedIn,
            extension: null,
            packageConstraints: [],
            behaviorChangeRisk: BehaviorChangeRisk::None,
            newCodePolicy: 'Fixture only.',
            existingCodePolicy: 'Fixture only.',
            guideline: 'Fixture only.',
            details: 'Synthetic rule used solely by unit tests.',
            examples: [new RuleExample(null, null, true)],
            verification: new RuleVerification([], null, null),
            sources: [new RuleSource(
                'php_source_upgrading',
                'https://raw.githubusercontent.com/php/php-src/php-8.2.0/UPGRADING',
                '2026-08-30',
            )],
            notes: [],
            supersededBy: null,
        );
    }

    /**
     * @param list<string> $allowedMinors ascending
     */
    private static function makePolicy(
        array $allowedMinors,
        string $featureCeiling,
        string $lifecycleCeiling,
        ResolutionMode $mode = ResolutionMode::RangeSafe,
    ): ResolvedPolicy {
        return new ResolvedPolicy(
            mode: $mode,
            projectRoot: '/tmp/fixture-project',
            declaredConstraint: null,
            allowedMinors: $allowedMinors,
            featureCeiling: $featureCeiling,
            lifecycleCeiling: $lifecycleCeiling,
            platformOverride: null,
            observedRuntime: null,
            coverage: new Coverage(CoverageStatus::Complete, '8.2', '8.5', false),
            confidence: Confidence::Declared,
            sources: [new PolicySource(SourceType::ComposerRequirePhp, 'composer.json', null)],
            warnings: [],
        );
    }
}

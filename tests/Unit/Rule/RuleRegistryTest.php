<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Rule;

use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Exception\RuleDataException;
use ModernPhpGuidelines\Exception\UnknownRuleException;
use ModernPhpGuidelines\Rule\ApplicabilityEvaluator;
use ModernPhpGuidelines\Rule\ApplicabilityStatus;
use ModernPhpGuidelines\Rule\RuleCategory;
use ModernPhpGuidelines\Rule\RuleKind;
use ModernPhpGuidelines\Rule\RuleQuery;
use ModernPhpGuidelines\Rule\RuleRegistry;
use PHPUnit\Framework\TestCase;

final class RuleRegistryTest extends TestCase
{
    use RuleFixtures;

    public function testRulesAreOrderedByIdAscending(): void
    {
        $registry = new RuleRegistry([
            self::makeRule('language.zeta', RuleKind::CompatibilityGuard, null, null, null),
            self::makeRule('language.alpha', RuleKind::CompatibilityGuard, null, null, null),
            self::makeRule('core.middle', RuleKind::CompatibilityGuard, null, null, null),
        ]);

        self::assertSame(['core.middle', 'language.alpha', 'language.zeta'], $registry->ids());
        self::assertSame(
            ['core.middle', 'language.alpha', 'language.zeta'],
            array_map(static fn($rule) => $rule->id, $registry->all()),
        );
    }

    public function testHasAndGet(): void
    {
        $rule = self::makeRule('language.alpha', RuleKind::CompatibilityGuard, null, null, null);
        $registry = new RuleRegistry([$rule]);

        self::assertTrue($registry->has('language.alpha'));
        self::assertFalse($registry->has('language.missing'));
        self::assertSame($rule, $registry->get('language.alpha'));
    }

    public function testGetUnknownIdThrowsWithSuggestion(): void
    {
        $registry = new RuleRegistry([
            self::makeRule('language.property_hooks', RuleKind::Feature, '8.4', null, null),
        ]);

        try {
            $registry->get('language.property_hook');
            self::fail('Expected UnknownRuleException.');
        } catch (UnknownRuleException $e) {
            self::assertStringContainsString('Unknown rule id "language.property_hook"', $e->getMessage());
            self::assertStringContainsString('Did you mean "language.property_hooks"?', $e->getMessage());
        }
    }

    public function testCount(): void
    {
        $registry = new RuleRegistry([
            self::makeRule('language.a', RuleKind::CompatibilityGuard, null, null, null),
            self::makeRule('language.b', RuleKind::CompatibilityGuard, null, null, null),
        ]);

        self::assertSame(2, $registry->count());
    }

    public function testDuplicateIdInConstructorFailsClosed(): void
    {
        $this->expectException(RuleDataException::class);
        $this->expectExceptionMessage('Duplicate rule id "language.a" in the rule registry.');

        new RuleRegistry([
            self::makeRule('language.a', RuleKind::CompatibilityGuard, null, null, null),
            self::makeRule('language.a', RuleKind::CompatibilityGuard, null, null, null),
        ]);
    }

    public function testFilterKeepsRegistryOrderAndAppliesKindFilter(): void
    {
        $registry = new RuleRegistry([
            self::makeRule('language.f84', RuleKind::Feature, '8.4', null, null),
            self::makeRule('language.d85', RuleKind::Deprecated, null, '8.5', null),
            self::makeRule('language.guard', RuleKind::CompatibilityGuard, null, null, null),
        ]);

        $policy = self::makePolicy(['8.2', '8.3', '8.4', '8.5'], '8.2', '8.5');
        $evaluator = new ApplicabilityEvaluator();

        $results = $registry->filter(new RuleQuery(kinds: [RuleKind::Deprecated]), $policy, $evaluator);

        self::assertCount(1, $results);
        self::assertSame('language.d85', $results[0]['rule']->id);
        self::assertSame(ApplicabilityStatus::DeprecatedInRange, $results[0]['applicability']->status);
    }

    public function testFilterExcludesNotInRangeByDefaultAndIncludesWithIncludeAll(): void
    {
        $registry = new RuleRegistry([
            self::makeRule('language.f90', RuleKind::Feature, '9.0', null, null),
        ]);

        $policy = self::makePolicy(['8.2', '8.3', '8.4', '8.5'], '8.2', '8.5');
        $evaluator = new ApplicabilityEvaluator();

        $default = $registry->filter(new RuleQuery(), $policy, $evaluator);
        self::assertCount(0, $default);

        $all = $registry->filter(new RuleQuery(includeAll: true), $policy, $evaluator);
        self::assertCount(1, $all);
        self::assertSame(ApplicabilityStatus::NotInRange, $all[0]['applicability']->status);
    }

    public function testFilterByCategoryPriorityExtensionAndStatus(): void
    {
        $registry = new RuleRegistry([
            self::makeRule('language.f84', RuleKind::Feature, '8.4', null, null),
            self::makeRule('core.bc84', RuleKind::BehaviorChange, '8.4', null, null),
        ]);

        $policy = self::makePolicy(['8.2', '8.3', '8.4', '8.5'], '8.2', '8.5');
        $evaluator = new ApplicabilityEvaluator();

        $byCategory = $registry->filter(new RuleQuery(categories: [RuleCategory::Core]), $policy, $evaluator);
        self::assertCount(1, $byCategory);
        self::assertSame('core.bc84', $byCategory[0]['rule']->id);

        $byStatus = $registry->filter(
            new RuleQuery(statuses: [ApplicabilityStatus::ForbiddenAboveFeatureCeiling]),
            $policy,
            $evaluator,
        );
        self::assertCount(2, $byStatus);
    }

    public function testFilterByMinorKeepsRulesWhoseGuidanceApplies(): void
    {
        $registry = new RuleRegistry([
            self::makeRule('language.d85', RuleKind::Deprecated, null, '8.5', null),
        ]);

        // Under single-target 8.2, the D=8.5 deprecation is not reached: status is `applicable`
        // and affected_minors is ["8.2"] (§4.3's "guidance applies", not "event fires" reading).
        $policy = self::makePolicy(['8.2'], '8.2', '8.2');
        $evaluator = new ApplicabilityEvaluator();

        $results = $registry->filter(new RuleQuery(minor: '8.2'), $policy, $evaluator);

        self::assertCount(1, $results);
        self::assertSame(ApplicabilityStatus::Applicable, $results[0]['applicability']->status);
    }

    public function testFilterMinorOutsideAllowedMinorsThrowsInputException(): void
    {
        $registry = new RuleRegistry([]);
        $policy = self::makePolicy(['8.2', '8.3'], '8.2', '8.3');
        $evaluator = new ApplicabilityEvaluator();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('--minor 8.5 is not in this project\'s allowed minors (8.2, 8.3).');

        $registry->filter(new RuleQuery(minor: '8.5'), $policy, $evaluator);
    }

    public function testFilterOrderIsAlwaysRegistryOrder(): void
    {
        $registry = new RuleRegistry([
            self::makeRule('language.zeta', RuleKind::CompatibilityGuard, null, null, null),
            self::makeRule('language.alpha', RuleKind::CompatibilityGuard, null, null, null),
        ]);

        $policy = self::makePolicy(['8.2'], '8.2', '8.2');
        $evaluator = new ApplicabilityEvaluator();

        $results = $registry->filter(new RuleQuery(), $policy, $evaluator);

        self::assertSame(['language.alpha', 'language.zeta'], array_map(
            static fn(array $pair) => $pair['rule']->id,
            $results,
        ));
    }
}

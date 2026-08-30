<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Rule;

use ModernPhpGuidelines\Php\KnownPhpMinors;
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Rule\ApplicabilityEvaluator;
use ModernPhpGuidelines\Rule\ApplicabilityStatus;
use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Rule\RuleRegistry;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The full 12 x 6 matrix of §4.5, plus the four extra required assertions.
 */
final class ApplicabilityEvaluatorTest extends TestCase
{
    use RuleFixtures;

    private const VALID_FIXTURES = __DIR__ . '/../../fixtures/rules/valid';

    private const APP = ApplicabilityStatus::Applicable;
    private const FAFC = ApplicabilityStatus::ForbiddenAboveFeatureCeiling;
    private const NIR = ApplicabilityStatus::NotInRange;
    private const DIR = ApplicabilityStatus::DeprecatedInRange;
    private const DAR = ApplicabilityStatus::DeprecatedAcrossRange;
    private const RIR = ApplicabilityStatus::RemovedInRange;
    private const RAR = ApplicabilityStatus::RemovedAcrossRange;

    /**
     * @return array<string, array{0: string, 1: string, 2: ApplicabilityStatus}>
     */
    public static function matrix(): array
    {
        // Rule => [P1, P2, P3, P4, P5, P6], in the §4.5 table's column order.
        $table = [
            'language.f82' => [self::APP, self::APP, self::APP, self::APP, self::APP, self::APP],
            'language.f84' => [self::FAFC, self::NIR, self::APP, self::APP, self::FAFC, self::FAFC],
            'language.f85' => [self::FAFC, self::NIR, self::FAFC, self::APP, self::NIR, self::NIR],
            'language.f90' => [self::NIR, self::NIR, self::NIR, self::NIR, self::NIR, self::NIR],
            'language.d82' => [self::DAR, self::DAR, self::DAR, self::DAR, self::DAR, self::DAR],
            'language.d85' => [self::DIR, self::APP, self::DIR, self::DAR, self::APP, self::APP],
            'language.r82' => [self::RAR, self::RAR, self::RAR, self::RAR, self::RAR, self::RAR],
            'language.r84' => [self::RIR, self::APP, self::RAR, self::RAR, self::RIR, self::RIR],
            'language.f83d85' => [self::DIR, self::NIR, self::DIR, self::DAR, self::FAFC, self::APP],
            'language.guard' => [self::APP, self::APP, self::APP, self::APP, self::APP, self::APP],
            'core.bc84' => [self::FAFC, self::NIR, self::APP, self::APP, self::FAFC, self::FAFC],
            'core.mp83' => [self::FAFC, self::NIR, self::APP, self::APP, self::FAFC, self::APP],
        ];

        $policyLabels = ['P1', 'P2', 'P3', 'P4', 'P5', 'P6'];

        $cases = [];
        foreach ($table as $ruleId => $statuses) {
            foreach ($statuses as $index => $status) {
                $label = $policyLabels[$index];
                $cases["$ruleId under $label"] = [$ruleId, $label, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function testMatrixCell(string $ruleId, string $policyLabel, ApplicabilityStatus $expected): void
    {
        $rule = $this->registry()->get($ruleId);
        $policy = self::policies()[$policyLabel];

        $result = (new ApplicabilityEvaluator())->evaluate($rule, $policy);

        self::assertSame($expected, $result->status, "$ruleId under $policyLabel");
    }

    public function testNonCollapseAcrossTheFixtureSet(): void
    {
        $registry = $this->registry();
        $evaluator = new ApplicabilityEvaluator();
        $policies = self::policies();

        $statusesFor = static function (ResolvedPolicy $policy) use ($registry, $evaluator): array {
            $map = [];
            foreach ($registry->all() as $rule) {
                $map[$rule->id] = $evaluator->evaluate($rule, $policy)->status;
            }

            return $map;
        };

        self::assertNotEquals($statusesFor($policies['P1']), $statusesFor($policies['P2']));
    }

    public function testFeatureAxisUnreachableFeaturesAreNotUsableAcrossRange(): void
    {
        $registry = $this->registry();
        $evaluator = new ApplicabilityEvaluator();

        foreach (self::policies() as $policy) {
            foreach ($registry->all() as $rule) {
                if (
                    $rule->introducedIn !== null
                    && KnownPhpMinors::compare($rule->introducedIn, $policy->featureCeiling) > 0
                    && $rule->removedIn === null
                    && $rule->deprecatedIn === null
                ) {
                    $result = $evaluator->evaluate($rule, $policy);
                    self::assertFalse(
                        $result->isUsableAcrossRange(),
                        sprintf('%s should not be usable across range under this policy.', $rule->id),
                    );
                }
            }
        }
    }

    public function testLifecycleAxisConsultsTheLifecycleCeilingEvenWhenFeatureCeilingIsLow(): void
    {
        $rule = $this->registry()->get('language.d85');
        $policy = self::policies()['P1'];

        $result = (new ApplicabilityEvaluator())->evaluate($rule, $policy);

        self::assertNotSame(ApplicabilityStatus::Applicable, $result->status);
    }

    public function testAffectedMinorsForD85UnderP5(): void
    {
        $rule = $this->registry()->get('language.d85');
        $policy = self::policies()['P5'];

        $result = (new ApplicabilityEvaluator())->evaluate($rule, $policy);

        self::assertSame(ApplicabilityStatus::Applicable, $result->status);
        self::assertSame(['8.2', '8.4'], $result->affectedMinors);
    }

    public function testAffectedMinorsForF83D85UnderP5(): void
    {
        $rule = $this->registry()->get('language.f83d85');
        $policy = self::policies()['P5'];

        $result = (new ApplicabilityEvaluator())->evaluate($rule, $policy);

        self::assertSame(ApplicabilityStatus::ForbiddenAboveFeatureCeiling, $result->status);
        self::assertSame(['8.4'], $result->affectedMinors);
    }

    /** @return array<string, ResolvedPolicy> */
    private static function policies(): array
    {
        return [
            'P1' => self::makePolicy(['8.2', '8.3', '8.4', '8.5'], '8.2', '8.5'),
            'P2' => self::makePolicy(['8.2'], '8.2', '8.2'),
            'P3' => self::makePolicy(['8.4', '8.5'], '8.4', '8.5'),
            'P4' => self::makePolicy(['8.5'], '8.5', '8.5'),
            'P5' => self::makePolicy(['8.2', '8.4'], '8.2', '8.4'),
            'P6' => self::makePolicy(['8.3', '8.4'], '8.3', '8.4'),
        ];
    }

    private function registry(): RuleRegistry
    {
        $loader = new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath()));

        return $loader->loadDirectory(self::VALID_FIXTURES);
    }
}

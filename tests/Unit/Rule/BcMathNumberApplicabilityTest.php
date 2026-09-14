<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Rule;

use ModernPhpGuidelines\Rule\ApplicabilityEvaluator;
use ModernPhpGuidelines\Rule\ApplicabilityStatus;
use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BcMathNumberApplicabilityTest extends TestCase
{
    use RuleFixtures;

    /**
     * @return iterable<string, array{list<string>, string, string, ApplicabilityStatus}>
     */
    public static function policyCases(): iterable
    {
        yield 'single PHP 8.2 does not contain the feature' => [
            ['8.2'],
            '8.2',
            '8.2',
            ApplicabilityStatus::NotInRange,
        ];
        yield 'broad range with an 8.2 floor forbids the feature' => [
            ['8.2', '8.3', '8.4', '8.5'],
            '8.2',
            '8.5',
            ApplicabilityStatus::ForbiddenAboveFeatureCeiling,
        ];
        yield 'an 8.4 floor makes the feature applicable' => [
            ['8.4', '8.5'],
            '8.4',
            '8.5',
            ApplicabilityStatus::Applicable,
        ];
    }

    /** @param list<string> $allowedMinors */
    #[DataProvider('policyCases')]
    public function testBcMathNumberUsesTheFeatureAxis(
        array $allowedMinors,
        string $featureCeiling,
        string $lifecycleCeiling,
        ApplicabilityStatus $expected,
    ): void {
        $rule = (new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath())))
            ->loadDirectory(PackagePaths::rulesDirectory())
            ->get('extension.bcmath_number');

        self::assertSame('8.4', $rule->introducedIn);

        $result = (new ApplicabilityEvaluator())->evaluate(
            $rule,
            self::makePolicy($allowedMinors, $featureCeiling, $lifecycleCeiling),
        );

        self::assertSame($expected, $result->status);
    }
}

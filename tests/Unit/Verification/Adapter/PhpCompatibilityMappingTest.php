<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification\Adapter;

use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use ModernPhpGuidelines\Verification\Adapter\PhpCompatibilityAdapter;
use PHPUnit\Framework\TestCase;

/** The committed SNIFF_RULE_MAP (§8.3), tested as data: shape, rule-id existence, exact contents. */
final class PhpCompatibilityMappingTest extends TestCase
{
    public function testCommittedMapMatchesTheReviewedTable(): void
    {
        self::assertSame([
            'PHPCompatibility.Classes.NewTypedConstants.Found' => ['language.typed_class_constants'],
            'PHPCompatibility.FunctionDeclarations.RemovedImplicitlyNullableParam.Deprecated' => [
                'language.implicitly_nullable_parameter_types',
            ],
            'PHPCompatibility.FunctionUse.NewFunctions.array_allFound' => ['core.array_find_functions'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_anyFound' => ['core.array_find_functions'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_findFound' => ['core.array_find_functions'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_find_keyFound' => ['core.array_find_functions'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_firstFound' => ['core.array_first_last'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_lastFound' => ['core.array_first_last'],
            'PHPCompatibility.FunctionUse.NewFunctions.json_validateFound' => ['core.json_validate'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated' => ['extension.curl_close'],
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.mysqli_reconnectRemoved' => [
                'extension.mysqli_driver_reconnect',
            ],
            'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax' => [
                'language.dollar_brace_string_interpolation',
            ],
            'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax' => [
                'language.dollar_brace_string_interpolation',
            ],
        ], self::committedMap());
    }

    public function testEveryMappedRuleIdExistsInTheCommittedCatalogue(): void
    {
        $rules = (new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath())))
            ->loadDirectory(PackagePaths::rulesDirectory());

        foreach (self::committedMap() as $sniffId => $ruleIds) {
            foreach ($ruleIds as $ruleId) {
                self::assertTrue(
                    $rules->has($ruleId),
                    sprintf('Mapped rule id "%s" (from sniff "%s") does not exist in the rule catalogue.', $ruleId, $sniffId),
                );
            }
        }
    }

    public function testMapKeysAreWellFormedSniffIdsUnderTheOwnedStandard(): void
    {
        $map = self::committedMap();
        $keys = array_keys($map);

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression(
                '/^PHPCompatibility\.[A-Za-z0-9]+\.[A-Za-z0-9]+\.[A-Za-z0-9_]+$/',
                $key,
            );
        }

        self::assertSame(array_values(array_unique($keys)), $keys);

        $sortedKeys = $keys;
        sort($sortedKeys, SORT_STRING);
        self::assertSame($sortedKeys, $keys, 'The committed map must be sorted by key.');
    }

    public function testMapValuesAreSortedUniqueAndNonEmpty(): void
    {
        foreach (self::committedMap() as $sniffId => $ruleIds) {
            self::assertNotSame([], $ruleIds, sprintf('Sniff "%s" maps to no rule id.', $sniffId));
            self::assertSame(
                array_values(array_unique($ruleIds)),
                $ruleIds,
                sprintf('Sniff "%s" rule ids must be unique.', $sniffId),
            );

            $sorted = $ruleIds;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $ruleIds, sprintf('Sniff "%s" rule ids must be sorted.', $sniffId));
        }
    }

    public function testNoInternalPseudoSniffIsMapped(): void
    {
        foreach (array_keys(self::committedMap()) as $key) {
            self::assertStringStartsNotWith('Internal.', $key);
        }
    }

    public function testRuleJsonVerificationFieldsRemainUnpopulated(): void
    {
        $files = glob(PackagePaths::rulesDirectory() . '/*.json');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw);
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertArrayHasKey('verification', $data);
            self::assertIsArray($data['verification']);
            self::assertArrayHasKey('phpcompatibility', $data['verification']);
            self::assertNull($data['verification']['phpcompatibility'], sprintf('%s: verification.phpcompatibility must stay null.', $file));
        }
    }

    /** @return array<string, list<string>> */
    private static function committedMap(): array
    {
        /** @var array<string, list<string>> $value */
        $value = (new \ReflectionClassConstant(PhpCompatibilityAdapter::class, 'SNIFF_RULE_MAP'))->getValue();

        return $value;
    }
}

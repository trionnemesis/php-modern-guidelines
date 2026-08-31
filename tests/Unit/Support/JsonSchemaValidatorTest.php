<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Support;

use ModernPhpGuidelines\Exception\RuleDataException;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonSchemaValidatorTest extends TestCase
{
    private const REMOTE_REF_SCHEMA = __DIR__ . '/../../fixtures/schemas/remote-ref.schema.json';
    private const INVALID_FIXTURES = __DIR__ . '/../../fixtures/rules/invalid';

    public function testValidRuleProducesNoErrors(): void
    {
        $validator = new JsonSchemaValidator(PackagePaths::ruleSchemaPath());
        $data = $this->decode(__DIR__ . '/../../fixtures/rules/valid/language.f82.json');

        self::assertSame([], $validator->validate($data));
    }

    public function testMissingRequiredPropertyProducesSortedPointerLines(): void
    {
        $validator = new JsonSchemaValidator(PackagePaths::ruleSchemaPath());
        $data = $this->decode(self::INVALID_FIXTURES . '/missing-required/language.missing_required.json');

        $errors = $validator->validate($data);

        self::assertNotSame([], $errors);
        self::assertSame($errors, $this->sorted($errors), 'errors must already be sorted (SORT_STRING)');
        self::assertStringContainsString('/: The required properties (guideline) are missing', implode("\n", $errors));
    }

    public function testAdditionalPropertyPointerIsTheWholeObject(): void
    {
        $validator = new JsonSchemaValidator(PackagePaths::ruleSchemaPath());
        $data = $this->decode(self::INVALID_FIXTURES . '/additional-property/language.additional_property.json');

        $errors = $validator->validate($data);

        self::assertContains('/: Additional object properties are not allowed: foo', $errors);
    }

    public function testPackageConstraintsArrayIsRejected(): void
    {
        $validator = new JsonSchemaValidator(PackagePaths::ruleSchemaPath());
        $data = $this->decode(self::INVALID_FIXTURES . '/package-constraints-array/language.package_constraints_array.json');

        $errors = $validator->validate($data);

        self::assertContains('/package_constraints: The data (array) must match the type: object', $errors);
    }

    /** @return iterable<string, array{mixed}> */
    public static function legacyPhpCompatibilityShapes(): iterable
    {
        yield 'legacy null' => [null];
        yield 'legacy single string' => ['PHPCompatibility.Classes.NewTypedConstants.Found'];
    }

    #[DataProvider('legacyPhpCompatibilityShapes')]
    public function testLegacyPhpCompatibilityScalarShapesAreRejected(mixed $legacyValue): void
    {
        $validator = new JsonSchemaValidator(PackagePaths::ruleSchemaPath());
        $data = $this->decode(__DIR__ . '/../../fixtures/rules/valid/language.f82.json');
        self::assertInstanceOf(\stdClass::class, $data->verification);
        $data->verification->phpcompatibility = $legacyValue;

        $errors = $validator->validate($data);

        self::assertNotSame([], $errors);
        self::assertStringContainsString(
            '/verification/phpcompatibility:',
            implode("\n", $errors),
        );
        self::assertStringContainsString('array', implode("\n", $errors));
    }

    public function testUnresolvedRemoteReferenceFailsRatherThanFetches(): void
    {
        $validator = new JsonSchemaValidator(self::REMOTE_REF_SCHEMA);

        $this->expectException(RuleDataException::class);
        $this->expectExceptionMessageMatches('/https:\/\/example\.com\/remote\.json/');

        $validator->validate(new \stdClass());
    }

    public function testConstructorRejectsAMissingSchemaFile(): void
    {
        $this->expectException(RuleDataException::class);

        new JsonSchemaValidator('/no/such/schema.json');
    }

    private function decode(string $path): \stdClass
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);

        return $decoded;
    }

    /**
     * @param  list<string> $errors
     * @return list<string>
     */
    private function sorted(array $errors): array
    {
        $copy = $errors;
        sort($copy, SORT_STRING);

        return $copy;
    }
}

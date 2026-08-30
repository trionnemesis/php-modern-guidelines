<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Rule;

use ModernPhpGuidelines\Exception\RuleDataException;
use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleLoaderTest extends TestCase
{
    private const VALID = __DIR__ . '/../../fixtures/rules/valid';
    private const INVALID = __DIR__ . '/../../fixtures/rules/invalid';

    public function testLoadsTheValidFixtureSet(): void
    {
        $registry = $this->loader()->loadDirectory(self::VALID);

        self::assertSame(12, $registry->count());
        self::assertContains('language.f82', $registry->ids());
        self::assertContains('core.mp83', $registry->ids());
    }

    public function testLoadingTheSameDirectoryTwiceIsDeterministic(): void
    {
        $first = $this->loader()->loadDirectory(self::VALID)->ids();
        $second = $this->loader()->loadDirectory(self::VALID)->ids();

        self::assertSame($first, $second);
    }

    public function testMissingDirectoryFailsClosed(): void
    {
        $this->expectException(RuleDataException::class);
        $this->expectExceptionMessage('does not exist');

        $this->loader()->loadDirectory(self::VALID . '/no-such-directory');
    }

    public function testEmptyDirectoryIsNotAnError(): void
    {
        $dir = sys_get_temp_dir() . '/php-modern-guidelines-empty-rules-' . bin2hex(random_bytes(8));
        mkdir($dir);

        try {
            $registry = $this->loader()->loadDirectory($dir);
            self::assertSame(0, $registry->count());
        } finally {
            rmdir($dir);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidCases(): array
    {
        return [
            'bad-json' => ['bad-json', 'not valid JSON'],
            'missing-required' => ['missing-required', 'does not match schemas/rule.schema.json'],
            'missing-required (pointer)' => ['missing-required', '/: The required properties (guideline) are missing'],
            'bad-id-pattern' => ['bad-id-pattern', '/id: The string should match pattern:'],
            'feature-without-introduced-in' => ['feature-without-introduced-in', '/introduced_in: The data (null) must match the type: string'],
            'deprecated-without-deprecated-in' => ['deprecated-without-deprecated-in', '/deprecated_in: The data (null) must match the type: string'],
            'removed-without-removed-in' => ['removed-without-removed-in', '/removed_in: The data (null) must match the type: string'],
            'extension-without-extension' => ['extension-without-extension', '/extension: The data (null) must match the type: string'],
            'example-both-forms' => ['example-both-forms', '/examples/0: The data must not match schema'],
            'example-empty' => ['example-empty', '/examples: Array should have at least 1 items, 0 found'],
            'bad-source-url' => ['bad-source-url', '/sources/0/url: The string should match pattern:'],
            'additional-property' => ['additional-property', 'Additional object properties are not allowed: foo'],
            'package-constraints-array' => ['package-constraints-array', '/package_constraints: The data (array) must match the type: object'],
            'filename-mismatch' => ['filename-mismatch', 'must be named "language.b.json"'],
            'category-prefix-mismatch' => ['category-prefix-mismatch', 'must start with its category segment'],
            'duplicate-id' => ['duplicate-id', 'must be named'],
        ];
    }

    #[DataProvider('invalidCases')]
    public function testInvalidFixtureIsRejectedDeterministically(string $case, string $expectedSubstring): void
    {
        $dir = self::INVALID . '/' . $case;
        self::assertDirectoryExists($dir, "fixture case \"$case\" is missing");

        try {
            $this->loader()->loadDirectory($dir);
            self::fail(sprintf('Expected RuleDataException loading invalid fixture "%s".', $case));
        } catch (RuleDataException $e) {
            self::assertStringContainsString($expectedSubstring, $e->getMessage());
        }
    }

    public function testTheMissingRequiredErrorMessageIsByteIdenticalAcrossTwoRuns(): void
    {
        $dir = self::INVALID . '/missing-required';

        $first = $this->messageFor($dir);
        $second = $this->messageFor($dir);

        self::assertSame($first, $second);
    }

    private function messageFor(string $dir): string
    {
        try {
            $this->loader()->loadDirectory($dir);
        } catch (RuleDataException $e) {
            return $e->getMessage();
        }

        self::fail('Expected RuleDataException.');
    }

    private function loader(): RuleLoader
    {
        return new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath()));
    }
}

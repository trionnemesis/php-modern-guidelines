<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaJsonTest extends TestCase
{
    #[DataProvider('schemaPaths')]
    public function testSchemaIsValidJson(string $path): void
    {
        $json = file_get_contents($path);
        if (!is_string($json)) {
            self::fail(sprintf('Could not read schema %s.', $path));
        }

        $schema = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($schema)) {
            self::fail(sprintf('Schema %s did not decode to an object.', $path));
        }

        $draft = $schema['$schema'] ?? null;
        if (!is_string($draft)) {
            self::fail(sprintf('Schema %s has no string $schema field.', $path));
        }

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $draft);
        self::assertArrayHasKey('properties', $schema);
    }

    /** @return iterable<string, array{string}> */
    public static function schemaPaths(): iterable
    {
        $root = dirname(__DIR__, 2);

        yield 'rule schema' => [$root . '/schemas/rule.schema.json'];
        yield 'policy schema' => [$root . '/schemas/policy.schema.json'];
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Support;

use ModernPhpGuidelines\Support\JsonPrinter;
use PHPUnit\Framework\TestCase;

final class JsonPrinterTest extends TestCase
{
    public function testEncodeIsPrettyPrintedWithFourSpaceIndent(): void
    {
        $json = JsonPrinter::encode(['a' => 1, 'b' => ['c' => 2]]);

        self::assertSame("{\n    \"a\": 1,\n    \"b\": {\n        \"c\": 2\n    }\n}", $json);
    }

    public function testEncodeDoesNotEscapeSlashes(): void
    {
        $json = JsonPrinter::encode(['path' => 'a/b/c']);

        self::assertStringContainsString('"a/b/c"', $json);
        self::assertStringNotContainsString('a\\/b\\/c', $json);
    }

    public function testEncodeDoesNotEscapeUnicode(): void
    {
        $json = JsonPrinter::encode(['name' => 'héllo']);

        self::assertStringContainsString('héllo', $json);
    }

    public function testEncodeHasNoTrailingNewline(): void
    {
        $json = JsonPrinter::encode(['a' => 1]);

        self::assertStringEndsNotWith("\n", $json);
    }

    public function testEncodePreservesKeyOrder(): void
    {
        $json = JsonPrinter::encode(['z' => 1, 'a' => 2, 'm' => 3]);

        $positions = [
            strpos($json, '"z"'),
            strpos($json, '"a"'),
            strpos($json, '"m"'),
        ];

        self::assertLessThan($positions[1], $positions[0]);
        self::assertLessThan($positions[2], $positions[1]);
    }

    public function testEncodeAcceptsNestedStdClassAsEmptyObject(): void
    {
        $json = JsonPrinter::encode(['package_constraints' => new \stdClass()]);

        self::assertSame("{\n    \"package_constraints\": {}\n}", $json);
    }

    public function testEncodeAcceptsNestedStdClassWithProperties(): void
    {
        $obj = new \stdClass();
        $obj->foo = 'bar';

        $json = JsonPrinter::encode(['x' => $obj]);

        self::assertSame("{\n    \"x\": {\n        \"foo\": \"bar\"\n    }\n}", $json);
    }

    public function testEncodeDoesNotForceListsIntoObjects(): void
    {
        $json = JsonPrinter::encode(['allowed_minors' => ['8.2', '8.3']]);

        self::assertSame("{\n    \"allowed_minors\": [\n        \"8.2\",\n        \"8.3\"\n    ]\n}", $json);
    }
}

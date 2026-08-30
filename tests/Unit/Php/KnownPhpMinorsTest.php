<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Php;

use ModernPhpGuidelines\Php\KnownPhpMinors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KnownPhpMinorsTest extends TestCase
{
    public function testAllStartsAtKnownMinAndEndsAtKnownMax(): void
    {
        $all = KnownPhpMinors::all();

        self::assertSame(KnownPhpMinors::KNOWN_MIN, $all[0]);
        self::assertSame(KnownPhpMinors::KNOWN_MAX, end($all));
    }

    public function testAllIsStrictlyAscending(): void
    {
        $all = KnownPhpMinors::all();

        for ($i = 1; $i < count($all); $i++) {
            self::assertLessThan(
                0,
                KnownPhpMinors::compare($all[$i - 1], $all[$i]),
                sprintf('%s should sort before %s', $all[$i - 1], $all[$i]),
            );
        }
    }

    public function testAllIsContiguous(): void
    {
        $all = KnownPhpMinors::all();

        for ($i = 1; $i < count($all); $i++) {
            [$prevMajor, $prevMinor] = array_map('intval', explode('.', $all[$i - 1]));
            [$major, $minor] = array_map('intval', explode('.', $all[$i]));

            self::assertSame($prevMajor, $major);
            self::assertSame($prevMinor + 1, $minor);
        }
    }

    public function testAllSharesOneMajor(): void
    {
        [$knownMinMajor] = explode('.', KnownPhpMinors::KNOWN_MIN);

        foreach (KnownPhpMinors::all() as $minor) {
            [$major] = explode('.', $minor);
            self::assertSame($knownMinMajor, $major);
        }
    }

    public function testContains(): void
    {
        self::assertTrue(KnownPhpMinors::contains('8.2'));
        self::assertTrue(KnownPhpMinors::contains('8.5'));
        self::assertFalse(KnownPhpMinors::contains('8.1'));
        self::assertFalse(KnownPhpMinors::contains('8.6'));
        self::assertFalse(KnownPhpMinors::contains('9.0'));
    }

    #[DataProvider('comparePairs')]
    public function testCompare(string $a, string $b, int $expectedSign): void
    {
        $actual = KnownPhpMinors::compare($a, $b);

        if ($expectedSign < 0) {
            self::assertLessThan(0, $actual);
        } elseif ($expectedSign > 0) {
            self::assertGreaterThan(0, $actual);
        } else {
            self::assertSame(0, $actual);
        }
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function comparePairs(): iterable
    {
        yield 'equal' => ['8.2', '8.2', 0];
        yield 'ascending minor' => ['8.2', '8.3', -1];
        yield 'descending minor' => ['8.3', '8.2', 1];
        yield 'numeric not lexicographic' => ['8.9', '8.10', -1];
        yield 'descending numeric not lexicographic' => ['8.10', '8.9', 1];
        yield 'major beats minor' => ['8.9', '9.0', -1];
    }

    public function testLowestAndHighest(): void
    {
        self::assertSame('8.2', KnownPhpMinors::lowest('8.2', '8.5'));
        self::assertSame('8.2', KnownPhpMinors::lowest('8.5', '8.2'));
        self::assertSame('8.5', KnownPhpMinors::highest('8.2', '8.5'));
        self::assertSame('8.5', KnownPhpMinors::highest('8.5', '8.2'));
        self::assertSame('8.2', KnownPhpMinors::lowest('8.2', '8.2'));
    }

    #[DataProvider('normalizeCases')]
    public function testNormalizeToMinor(string $input, ?string $expected): void
    {
        self::assertSame($expected, KnownPhpMinors::normalizeToMinor($input));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function normalizeCases(): iterable
    {
        yield 'minor only' => ['8.4', '8.4'];
        yield 'full version' => ['8.4.19', '8.4'];
        yield 'zero patch' => ['8.2.0', '8.2'];
        yield 'garbage' => ['8.x', null];
        yield 'caret prefix' => ['^8.2', null];
        yield 'empty' => ['', null];
        yield 'pre-release suffix' => ['8.2.0RC1', null];
    }

    public function testNextAfterKnownMax(): void
    {
        self::assertSame('8.6', KnownPhpMinors::nextAfterKnownMax());
    }
}

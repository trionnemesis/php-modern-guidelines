<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Php;

use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Php\MinorRangeCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MinorRangeCalculatorTest extends TestCase
{
    private MinorRangeCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MinorRangeCalculator();
    }

    public function testParseThrowsInputExceptionOnUnparseableConstraint(): void
    {
        $this->expectException(InputException::class);
        $this->calculator->parse('not a constraint');
    }

    /**
     * §3.6 case table (cases A-R) and the §3.11 fixture constraints: constraint -> allowed known minors.
     *
     * @param list<string> $expected
     */
    #[DataProvider('allowedKnownMinorsCases')]
    public function testAllowedKnownMinors(string $constraint, array $expected): void
    {
        $parsed = $this->calculator->parse($constraint);

        self::assertSame($expected, $this->calculator->allowedKnownMinors($parsed));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function allowedKnownMinorsCases(): iterable
    {
        yield 'A ^8.2' => ['^8.2', ['8.2', '8.3', '8.4', '8.5']];
        yield 'B ^8.4' => ['^8.4', ['8.4', '8.5']];
        yield 'C ~8.2.0' => ['~8.2.0', ['8.2']];
        yield 'D ~8.2' => ['~8.2', ['8.2', '8.3', '8.4', '8.5']];
        yield 'E >=8.2 <8.5' => ['>=8.2 <8.5', ['8.2', '8.3', '8.4']];
        yield 'F ~8.2.0 || ~8.4.0' => ['~8.2.0 || ~8.4.0', ['8.2', '8.4']];
        yield 'G 8.3.7' => ['8.3.7', ['8.3']];
        yield 'H >=8.2' => ['>=8.2', ['8.2', '8.3', '8.4', '8.5']];
        yield 'I >=8.0' => ['>=8.0', ['8.2', '8.3', '8.4', '8.5']];
        yield 'J ^7.4 (empty)' => ['^7.4', []];
        yield 'K >=9.0 (empty)' => ['>=9.0', []];
        yield 'Q or-hole' => ['>=8.2 <8.3 || >=8.4 <9.0', ['8.2', '8.4', '8.5']];
        yield 'R patch-exclusion' => ['^8.2 !=8.3.0', ['8.2', '8.3', '8.4', '8.5']];
        yield 'exact minor 8.2.0' => ['8.2.0', ['8.2']];
        yield 'exact minor 8.3.0' => ['8.3.0', ['8.3']];
        yield 'exact minor 8.5.0' => ['8.5.0', ['8.5']];
    }

    /**
     * §3.2 subtractConflict table, verified against composer/semver 3.4.4.
     *
     * @param list<string> $removed
     */
    #[DataProvider('subtractConflictCases')]
    public function testSubtractConflict(string $conflict, array $removed): void
    {
        $all = ['8.2', '8.3', '8.4', '8.5'];
        $conflictConstraint = $this->calculator->parse($conflict);

        $expected = array_values(array_diff($all, $removed));

        self::assertSame($expected, $this->calculator->subtractConflict($all, $conflictConstraint));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function subtractConflictCases(): iterable
    {
        yield '8.3.* removes 8.3' => ['8.3.*', ['8.3']];
        yield '8.3.5 removes nothing (patch-level)' => ['8.3.5', []];
        yield '>=8.3 <8.5 removes 8.3, 8.4' => ['>=8.3 <8.5', ['8.3', '8.4']];
        yield '^8.3 removes 8.3, 8.4, 8.5' => ['^8.3', ['8.3', '8.4', '8.5']];
        yield '>=8.2 removes everything' => ['>=8.2', ['8.2', '8.3', '8.4', '8.5']];
    }

    public function testAllowsAboveKnownMax(): void
    {
        self::assertTrue($this->calculator->allowsAboveKnownMax($this->calculator->parse('^8.2')));
        self::assertTrue($this->calculator->allowsAboveKnownMax($this->calculator->parse('>=8.2')));
        self::assertFalse($this->calculator->allowsAboveKnownMax($this->calculator->parse('~8.2.0')));
        self::assertFalse($this->calculator->allowsAboveKnownMax($this->calculator->parse('>=8.2 <8.5')));
    }

    public function testAllowsBelowKnownMin(): void
    {
        self::assertTrue($this->calculator->allowsBelowKnownMin($this->calculator->parse('>=8.0')));
        self::assertTrue($this->calculator->allowsBelowKnownMin($this->calculator->parse('^7.4')));
        self::assertFalse($this->calculator->allowsBelowKnownMin($this->calculator->parse('^8.2')));
        self::assertFalse($this->calculator->allowsBelowKnownMin($this->calculator->parse('~8.2.0')));
    }

    public function testIsUnbounded(): void
    {
        self::assertTrue($this->calculator->isUnbounded($this->calculator->parse('>=8.2')));
        self::assertTrue($this->calculator->isUnbounded($this->calculator->parse('*')));
        self::assertFalse($this->calculator->isUnbounded($this->calculator->parse('^8.2')));
        // Documented heuristic false positive (§3.2): a huge but finite upper bound still reads as
        // "unbounded" because it reaches the 99999.0.0 sentinel. Warning-text selection only.
        self::assertTrue($this->calculator->isUnbounded($this->calculator->parse('>=8.2 <100000.0')));
    }
}

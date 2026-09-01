<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Rule;

use ModernPhpGuidelines\Rule\RuleVerification;
use PHPUnit\Framework\TestCase;

final class RuleVerificationTest extends TestCase
{
    public function testOrderedListRoundTripsWithoutFlattening(): void
    {
        $identifiers = [
            'PHPCompatibility.FunctionUse.NewFunctions.array_allFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_anyFound',
        ];

        $verification = new RuleVerification($identifiers, null, null);

        self::assertSame($identifiers, $verification->toArray()['phpcompatibility']);
    }

    public function testDuplicateIdentifiersAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be unique');

        new RuleVerification(['Sniff.Id', 'Sniff.Id'], null, null);
    }

    public function testUnsortedIdentifiersAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be sorted');

        new RuleVerification(['Sniff.Z', 'Sniff.A'], null, null);
    }
}

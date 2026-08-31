<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification;

use ModernPhpGuidelines\Verification\Process\ProcessState;
use ModernPhpGuidelines\Verification\VerificationInvocation;
use PHPUnit\Framework\TestCase;

final class VerificationInvocationTest extends TestCase
{
    public function testPolicyMinorsMustBeOrderedAscending(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ordered ascending');

        new VerificationInvocation(
            'verification-1',
            ['8.4', '8.3'],
            'phpcs',
            ['--report=json'],
            ProcessState::Exited,
            0,
        );
    }
}

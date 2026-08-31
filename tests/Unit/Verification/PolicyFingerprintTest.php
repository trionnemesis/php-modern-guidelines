<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification;

use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\PolicyResolver;
use ModernPhpGuidelines\Policy\ResolutionMode;
use ModernPhpGuidelines\Verification\PolicyFingerprint;
use PHPUnit\Framework\TestCase;

final class PolicyFingerprintTest extends TestCase
{
    public function testFingerprintIsPinnedAndExcludesCheckoutPath(): void
    {
        $fixture = realpath(__DIR__ . '/../../fixtures/projects/caret-8-2');
        self::assertIsString($fixture);

        $policy = (new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator()))
            ->resolve(new PolicyRequest($fixture, ResolutionMode::RangeSafe, null));

        self::assertSame(
            'sha256:6c146a35e4a83b481ad68a3cbc8c2fc58b15cba1e7dc5b2661a0cac262fa336e',
            PolicyFingerprint::forPolicy($policy),
        );
        self::assertStringNotContainsString($fixture, PolicyFingerprint::forPolicy($policy));
    }
}

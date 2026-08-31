<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification\Adapter;

use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\PolicyResolver;
use ModernPhpGuidelines\Policy\ResolutionMode;
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Verification\Adapter\UnavailableAdapter;
use ModernPhpGuidelines\Verification\AdapterPlan;
use ModernPhpGuidelines\Verification\ExecutableLocator;
use ModernPhpGuidelines\Verification\ProjectionStatus;
use ModernPhpGuidelines\Verification\VerificationReason;
use ModernPhpGuidelines\Verification\VerificationRequest;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the retained, no-longer-registered M3-A placeholder covered. No process is started (plan()
 * only locates), so this class is not in the process-isolation group.
 */
final class UnavailableAdapterTest extends TestCase
{
    private const MISSING = '/definitely/not-installed/phpcs';

    public function testMissingExecutableIsNotEvaluatedWithExecutableUnavailable(): void
    {
        $request = new VerificationRequest(self::resolvePolicy('comparison-range'), self::MISSING);
        $adapter = new UnavailableAdapter('phpcompatibility', new ExecutableLocator());

        $plan = $adapter->plan($request);

        self::assertSame(ProjectionStatus::NotEvaluated, $plan->projectionStatus);
        self::assertSame([], $plan->invocations);
        self::assertNotNull($plan->reason);
        self::assertSame(VerificationReason::EXECUTABLE_UNAVAILABLE, $plan->reason->code);
        self::assertSame('The selected executable is not available or executable.', $plan->reason->message);
    }

    public function testExistingExecutableIsNotEvaluatedWithCapabilityUnavailable(): void
    {
        $request = new VerificationRequest(self::resolvePolicy('comparison-range'), self::stub());
        $adapter = new UnavailableAdapter('phpcompatibility', new ExecutableLocator());

        $plan = $adapter->plan($request);

        self::assertSame(ProjectionStatus::NotEvaluated, $plan->projectionStatus);
        self::assertSame([], $plan->invocations);
        self::assertNotNull($plan->reason);
        self::assertSame(VerificationReason::CAPABILITY_UNAVAILABLE, $plan->reason->code);
        self::assertSame(
            'The phpcompatibility verification adapter is not implemented in this build.',
            $plan->reason->message,
        );
    }

    public function testVerifyHasNoExecutablePhaseAndThrows(): void
    {
        $adapter = new UnavailableAdapter('phpcompatibility', new ExecutableLocator());
        $request = new VerificationRequest(self::resolvePolicy('comparison-range'), self::MISSING);
        $plan = new AdapterPlan(
            ProjectionStatus::NotEvaluated,
            [],
            new VerificationReason(VerificationReason::EXECUTABLE_UNAVAILABLE, 'unused'),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The M3-A unavailable adapter has no executable verification phase.');

        $adapter->verify($request, $plan);
    }

    private static function stub(): string
    {
        $path = realpath(__DIR__ . '/../../../fixtures/verification/stub/phpcs-stub');
        self::assertIsString($path);

        return $path;
    }

    private static function resolvePolicy(string $fixture): ResolvedPolicy
    {
        $root = realpath(__DIR__ . '/../../../fixtures/projects/' . $fixture);
        self::assertIsString($root);

        return (new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator()))
            ->resolve(new PolicyRequest($root, ResolutionMode::RangeSafe));
    }
}

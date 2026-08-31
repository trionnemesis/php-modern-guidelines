<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification;

use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\PolicyResolver;
use ModernPhpGuidelines\Policy\ResolutionMode;
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Verification\InvocationPurpose;
use ModernPhpGuidelines\Verification\PlannedVerificationInvocation;
use ModernPhpGuidelines\Verification\Process\NativeProcessRunner;
use ModernPhpGuidelines\Verification\Process\ProcessState;
use ModernPhpGuidelines\Verification\VerificationExecutor;
use ModernPhpGuidelines\Verification\VerificationRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class VerificationExecutorTest extends TestCase
{
    private const MISSING = '/definitely/not-installed/phpcs';

    protected function setUp(): void
    {
        // A lost executable bit on the committed stub must fail loudly here rather than silently
        // degrading every case in this class that runs it through the real executor.
        self::assertTrue(is_executable(self::stub()));
    }

    public function testUnlocatableExecutableIsRecordedAsAStartFailureWithoutRunning(): void
    {
        $request = new VerificationRequest(self::resolvePolicy('comparison-range'), self::MISSING);
        $planned = new PlannedVerificationInvocation(
            'verification-1',
            [],
            $request->evidenceExecutable(),
            ['--version'],
            InvocationPurpose::ToolProbe,
        );

        $result = (new VerificationExecutor())->execute($request, $planned);

        self::assertSame($planned->id, $result->invocation->id);
        self::assertSame($planned->policyMinors, $result->invocation->policyMinors);
        self::assertSame($planned->executable, $result->invocation->executable);
        self::assertSame($planned->arguments, $result->invocation->arguments);
        self::assertSame($planned->purpose, $result->invocation->purpose);
        self::assertSame($planned->workingDirectory, $result->invocation->workingDirectory);
        self::assertSame($planned->timeoutMilliseconds, $result->invocation->timeoutMilliseconds);
        self::assertSame($planned->environment, $result->invocation->environment);
        self::assertSame(ProcessState::StartFailed, $result->invocation->status);
        self::assertNull($result->invocation->exitCode);
        self::assertNull($result->invocation->signal);
        self::assertSame('', $result->stdout);
    }

    #[Group('process-isolation')]
    public function testExecutedRecordIsDerivedFromThePlannedDescriptor(): void
    {
        self::requireProcessIsolation();

        $request = new VerificationRequest(self::resolvePolicy('comparison-range'), self::stub());
        $planned = new PlannedVerificationInvocation(
            'verification-1',
            [],
            $request->evidenceExecutable(),
            ['--version'],
            InvocationPurpose::ToolProbe,
        );

        $result = (new VerificationExecutor())->execute($request, $planned);

        $roundTripped = PlannedVerificationInvocation::fromExecuted($result->invocation);
        self::assertSame($planned->id, $roundTripped->id);
        self::assertSame($planned->policyMinors, $roundTripped->policyMinors);
        self::assertSame($planned->executable, $roundTripped->executable);
        self::assertSame($planned->arguments, $roundTripped->arguments);
        self::assertSame($planned->purpose, $roundTripped->purpose);
        self::assertSame($planned->workingDirectory, $roundTripped->workingDirectory);
        self::assertSame($planned->timeoutMilliseconds, $roundTripped->timeoutMilliseconds);
        self::assertSame($planned->environment, $roundTripped->environment);

        self::assertTrue($planned->matchesExecuted($result->invocation));
        self::assertSame('<external>/phpcs-stub', $request->evidenceExecutable());
        self::assertSame($request->evidenceExecutable(), $result->invocation->executable);
    }

    #[Group('process-isolation')]
    public function testStdoutIsReturnedForTheSameInvocation(): void
    {
        self::requireProcessIsolation();

        $request = new VerificationRequest(self::resolvePolicy('comparison-range'), self::stub());
        $planned = new PlannedVerificationInvocation(
            'verification-1',
            [],
            $request->evidenceExecutable(),
            ['--version'],
            InvocationPurpose::ToolProbe,
        );

        $result = (new VerificationExecutor())->execute($request, $planned);

        self::assertSame(
            "PHP_CodeSniffer version 3.13.6 (stable) by Squiz and PHPCSStandards\n",
            $result->stdout,
        );
        self::assertSame($planned->id, $result->invocation->id);
        self::assertSame($planned->purpose, $result->invocation->purpose);
    }

    #[Group('process-isolation')]
    public function testProjectRootRoleResolvesToTheResolvedPolicyRoot(): void
    {
        self::requireProcessIsolation();

        $policy = self::resolvePolicy('comparison-range');
        $request = new VerificationRequest($policy, self::stub());
        $planned = new PlannedVerificationInvocation(
            'verification-1',
            [],
            $request->evidenceExecutable(),
            ['--stub-print-cwd'],
            InvocationPurpose::ToolProbe,
        );

        $result = (new VerificationExecutor())->execute($request, $planned);

        $expected = realpath($policy->projectRoot);
        self::assertIsString($expected);
        self::assertSame($expected, trim($result->stdout));
    }

    private static function requireProcessIsolation(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The committed stub is a POSIX executable script.');
        }

        if (!NativeProcessRunner::isSupportedOnCurrentPlatform()) {
            self::markTestSkipped(
                'This case executes a child process through the core executor, which requires operational '
                . 'Linux user/PID-namespace isolation.',
            );
        }
    }

    private static function stub(): string
    {
        $path = realpath(__DIR__ . '/../../fixtures/verification/stub/phpcs-stub');
        self::assertIsString($path);

        return $path;
    }

    private static function resolvePolicy(string $fixture): ResolvedPolicy
    {
        $root = realpath(__DIR__ . '/../../fixtures/projects/' . $fixture);
        self::assertIsString($root);

        return (new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator()))
            ->resolve(new PolicyRequest($root, ResolutionMode::RangeSafe));
    }
}

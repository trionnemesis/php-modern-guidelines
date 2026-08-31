<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification\Adapter;

use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use ModernPhpGuidelines\Policy\Confidence;
use ModernPhpGuidelines\Policy\Coverage;
use ModernPhpGuidelines\Policy\CoverageStatus;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\PolicyResolver;
use ModernPhpGuidelines\Policy\PolicySource;
use ModernPhpGuidelines\Policy\ResolutionMode;
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Policy\SourceType;
use ModernPhpGuidelines\Verification\Adapter\PhpCompatibilityAdapter;
use ModernPhpGuidelines\Verification\InvocationEnvironment;
use ModernPhpGuidelines\Verification\InvocationPurpose;
use ModernPhpGuidelines\Verification\InvocationWorkingDirectory;
use ModernPhpGuidelines\Verification\PolicyProjectionValidator;
use ModernPhpGuidelines\Verification\ProjectionStatus;
use ModernPhpGuidelines\Verification\VerificationReason;
use ModernPhpGuidelines\Verification\VerificationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * plan() is pure — it starts no external process (case 8 proves this mechanically) — so this class is
 * not in the process-isolation group; it only needs the Windows guard (the committed stub is a POSIX
 * executable script) and the mode-bit assertion.
 */
final class PhpCompatibilityProjectionTest extends TestCase
{
    private const MISSING = '/definitely/not-installed/phpcs';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The committed stub is a POSIX executable script.');
        }

        self::assertTrue(is_executable(self::stub()));
    }

    public function testSupportedPlanUsesTheCommittedCanonicalArgv(): void
    {
        $policy = self::resolvePolicy('comparison-range');
        $request = new VerificationRequest($policy, self::stub());

        $plan = (new PhpCompatibilityAdapter())->plan($request);

        self::assertSame(ProjectionStatus::Supported, $plan->projectionStatus);
        self::assertNull($plan->reason);
        self::assertCount(3, $plan->invocations);

        [$probe1, $probe2, $analysis] = $plan->invocations;

        self::assertSame('verification-1', $probe1->id);
        self::assertSame(InvocationPurpose::ToolProbe, $probe1->purpose);
        self::assertSame([], $probe1->policyMinors);
        self::assertSame(['--version'], $probe1->arguments);

        self::assertSame('verification-2', $probe2->id);
        self::assertSame(InvocationPurpose::ToolProbe, $probe2->purpose);
        self::assertSame([], $probe2->policyMinors);
        self::assertSame(['-i'], $probe2->arguments);

        self::assertSame('verification-3', $analysis->id);
        self::assertSame(InvocationPurpose::Analysis, $analysis->purpose);
        self::assertSame(['8.2', '8.3', '8.4'], $analysis->policyMinors);
        self::assertSame([
            '--standard=PHPCompatibility',
            '--runtime-set',
            'testVersion',
            '8.2-8.4',
            '--report=json',
            '--basepath=.',
            '--parallel=1',
            '--extensions=php',
            '--severity=1',
            '--no-cache',
            '-q',
            '.',
        ], $analysis->arguments);

        foreach ($plan->invocations as $invocation) {
            self::assertSame('<external>/phpcs-stub', $invocation->executable);
            self::assertSame($request->evidenceExecutable(), $invocation->executable);
            self::assertSame(300_000, $invocation->timeoutMilliseconds);
            self::assertSame(InvocationWorkingDirectory::ProjectRoot, $invocation->workingDirectory);
            self::assertSame(InvocationEnvironment::Sanitized, $invocation->environment);
        }
    }

    public function testSingleMinorPolicyUsesTheBareTestVersionForm(): void
    {
        $request = new VerificationRequest(self::resolvePolicy('exact-version'), self::stub());

        $plan = (new PhpCompatibilityAdapter())->plan($request);

        self::assertSame(ProjectionStatus::Supported, $plan->projectionStatus);
        $analysis = $plan->invocations[2];
        self::assertSame(['8.3'], $analysis->policyMinors);
        self::assertSame('8.3', $analysis->arguments[3]);
    }

    public function testPlanPassesTheCorePolicyProjectionValidator(): void
    {
        $validator = new PolicyProjectionValidator();
        $adapter = new PhpCompatibilityAdapter();

        $cases = [
            self::resolvePolicy('comparison-range'),
            self::resolvePolicy('exact-version'),
            self::resolvePolicy('caret-8-2', ResolutionMode::SingleTarget),
            self::resolvePolicy('caret-8-2', ResolutionMode::RuntimeObserved),
        ];

        foreach ($cases as $policy) {
            $request = new VerificationRequest($policy, self::stub());
            $plan = $adapter->plan($request);

            self::assertSame(ProjectionStatus::Supported, $plan->projectionStatus);
            $validator->assertExact($request, $plan);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unprojectablePolicyCases(): iterable
    {
        $openCoverageGap = 'The resolved range-safe policy cannot be projected exactly: known coverage is '
            . 'coverage_gap and the upper bound is open.';

        yield 'caret constraint leaves an open coverage gap (rule 2)' => ['caret-8-2', $openCoverageGap];
        yield 'unbounded upper constraint leaves an open coverage gap (rule 2)' => [
            'open-upper-unbounded',
            $openCoverageGap,
        ];
        yield 'no declared php constraint leaves unknown coverage (rule 2)' => [
            'no-php-constraint',
            'The resolved range-safe policy cannot be projected exactly: known coverage is unknown and the '
            . 'upper bound is closed.',
        ];
        yield 'a patch-level exclusion removes no minor so rule 4 cannot apply, but rule 2 fires on the '
            . 'coverage gap' => ['patch-exclusion', $openCoverageGap];
        yield 'a non-contiguous allowed set still fails on the coverage gap before rule 4 is reached '
            . '(rule 2 fires first)' => ['or-hole', $openCoverageGap];
        yield 'a php conflict leaves a non-contiguous allowed set, but rule 2 fires first on the coverage '
            . 'gap' => ['conflict-php', $openCoverageGap];
        yield 'rule 4 fires in isolation when coverage is complete and closed' => [
            'or-constraint',
            'The resolved policy allows PHP 8.2, 8.4 but excludes PHP 8.3, and a PHPCompatibility '
            . 'testVersion range cannot express that gap.',
        ];
    }

    #[DataProvider('unprojectablePolicyCases')]
    public function testUnprojectablePolicyIsUnsupportedWithTheNamedReason(
        string $fixture,
        string $expectedMessage,
    ): void {
        $request = new VerificationRequest(self::resolvePolicy($fixture), self::stub());

        $plan = (new PhpCompatibilityAdapter())->plan($request);

        self::assertSame(ProjectionStatus::Unsupported, $plan->projectionStatus);
        self::assertSame([], $plan->invocations);
        self::assertNotNull($plan->reason);
        self::assertSame(VerificationReason::POLICY_PROJECTION_UNSUPPORTED, $plan->reason->code);
        self::assertSame($expectedMessage, $plan->reason->message);
    }

    public function testSingleTargetAndRuntimeObservedModesProjectExactly(): void
    {
        $singleTargetRequest = new VerificationRequest(
            self::resolvePolicy('caret-8-2', ResolutionMode::SingleTarget),
            self::stub(),
        );
        $singleTargetPlan = (new PhpCompatibilityAdapter())->plan($singleTargetRequest);

        self::assertSame(ProjectionStatus::Supported, $singleTargetPlan->projectionStatus);
        $singleTargetAnalysis = $singleTargetPlan->invocations[2];
        self::assertSame(['8.2'], $singleTargetAnalysis->policyMinors);
        self::assertSame('8.2', $singleTargetAnalysis->arguments[3]);

        $runtimeObservedRequest = new VerificationRequest(
            self::resolvePolicy('caret-8-2', ResolutionMode::RuntimeObserved),
            self::stub(),
        );
        $runtimeObservedPlan = (new PhpCompatibilityAdapter())->plan($runtimeObservedRequest);

        $runtimeMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        self::assertSame(ProjectionStatus::Supported, $runtimeObservedPlan->projectionStatus);
        $runtimeObservedAnalysis = $runtimeObservedPlan->invocations[2];
        self::assertSame([$runtimeMinor], $runtimeObservedAnalysis->policyMinors);
        self::assertSame($runtimeMinor, $runtimeObservedAnalysis->arguments[3]);
    }

    public function testRuntimeObservedMinorOutsideKnownCoverageIsUnsupported(): void
    {
        $policy = new ResolvedPolicy(
            mode: ResolutionMode::RuntimeObserved,
            projectRoot: self::projectRoot('comparison-range'),
            declaredConstraint: null,
            allowedMinors: ['8.6'],
            featureCeiling: '8.6',
            lifecycleCeiling: '8.6',
            platformOverride: null,
            observedRuntime: '8.6.0',
            coverage: new Coverage(CoverageStatus::CoverageGap, '8.2', '8.5', true),
            confidence: Confidence::Observed,
            sources: [new PolicySource(SourceType::Runtime, null, '8.6.0')],
            warnings: [],
        );
        $request = new VerificationRequest($policy, self::stub());

        $plan = (new PhpCompatibilityAdapter())->plan($request);

        self::assertSame(ProjectionStatus::Unsupported, $plan->projectionStatus);
        self::assertSame([], $plan->invocations);
        self::assertNotNull($plan->reason);
        self::assertSame(VerificationReason::POLICY_PROJECTION_UNSUPPORTED, $plan->reason->code);
        self::assertSame(
            'The resolved policy names PHP 8.6, which is outside the PHP minors this tool knows (8.2-8.5), '
            . 'so it cannot be projected exactly.',
            $plan->reason->message,
        );
    }

    public function testMissingExecutableIsNotEvaluatedBeforeAnyPolicyCheck(): void
    {
        // caret-8-2 is an unprojectable policy (open coverage gap); if the adapter checked the policy
        // before availability, this would report exit 9 instead of exit 7.
        $request = new VerificationRequest(self::resolvePolicy('caret-8-2'), self::MISSING);

        $plan = (new PhpCompatibilityAdapter())->plan($request);

        self::assertSame(ProjectionStatus::NotEvaluated, $plan->projectionStatus);
        self::assertSame([], $plan->invocations);
        self::assertNotNull($plan->reason);
        self::assertSame(VerificationReason::EXECUTABLE_UNAVAILABLE, $plan->reason->code);
        self::assertSame('The selected executable is not available or executable.', $plan->reason->message);
    }

    public function testPlanStartsNoProcess(): void
    {
        $temporaryDirectory = sys_get_temp_dir()
            . '/php-modern-guidelines-plan-no-process-'
            . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($temporaryDirectory, 0700));
        self::assertNotFalse(file_put_contents(
            $temporaryDirectory . '/composer.json',
            '{"name": "fixture/plan-no-process", "require": {"php": ">=8.2 <8.5"}}',
        ));
        $selectedExecutable = $temporaryDirectory . '/must-not-run';
        $marker = $temporaryDirectory . '/executed';
        self::assertNotFalse(file_put_contents(
            $selectedExecutable,
            "#!/bin/sh\n: > " . escapeshellarg($marker) . "\n",
        ));
        self::assertTrue(chmod($selectedExecutable, 0700));

        try {
            $policy = (new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator()))
                ->resolve(new PolicyRequest($temporaryDirectory, ResolutionMode::RangeSafe));
            $request = new VerificationRequest($policy, $selectedExecutable);

            $plan = (new PhpCompatibilityAdapter())->plan($request);

            self::assertSame(ProjectionStatus::Supported, $plan->projectionStatus);
            self::assertFileDoesNotExist($marker);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
            if (is_file($selectedExecutable)) {
                unlink($selectedExecutable);
            }
            if (is_file($temporaryDirectory . '/composer.json')) {
                unlink($temporaryDirectory . '/composer.json');
            }
            if (is_dir($temporaryDirectory)) {
                rmdir($temporaryDirectory);
            }
        }
    }

    private static function stub(): string
    {
        $path = realpath(__DIR__ . '/../../../fixtures/verification/stub/phpcs-stub');
        self::assertIsString($path);

        return $path;
    }

    private static function projectRoot(string $fixture): string
    {
        $path = realpath(__DIR__ . '/../../../fixtures/projects/' . $fixture);
        self::assertIsString($path);

        return $path;
    }

    private static function resolvePolicy(
        string $fixture,
        ResolutionMode $mode = ResolutionMode::RangeSafe,
    ): ResolvedPolicy {
        return (new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator()))
            ->resolve(new PolicyRequest(self::projectRoot($fixture), $mode));
    }
}

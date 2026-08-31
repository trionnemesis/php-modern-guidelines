<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification;

use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerificationSchemaContractTest extends TestCase
{
    public function testSupportedReportRequiresAPlannedInvocation(): void
    {
        $report = $this->goldenObject();
        $this->policy($report)->planned_invocations = [];

        self::assertNotSame([], $this->validate($report));
    }

    public function testUnavailableNotEvaluatedReportRejectsAPlan(): void
    {
        $report = $this->goldenObject();
        $report->status = 'unavailable';
        $report->exit_code = 7;
        $adapter = $report->adapter ?? null;
        self::assertInstanceOf(\stdClass::class, $adapter);
        $adapter->tool_version = null;
        $this->policy($report)->projection_status = 'not_evaluated';
        $report->invocations = [];
        $report->summary = (object) [
            'invocation_count' => 0,
            'finding_count' => 0,
            'mapped_finding_count' => 0,
            'unmapped_finding_count' => 0,
            'mapping_count' => 0,
            'mapped_rule_count' => 0,
        ];
        $report->reason = (object) [
            'code' => 'adapter.executable_unavailable',
            'message' => 'The executable is unavailable.',
        ];
        $report->rule_contexts = [];
        $report->findings = [];

        self::assertNotSame([], $this->validate($report));
    }

    public function testSingleTargetPolicyRejectsSeveralAllowedMinors(): void
    {
        $report = $this->goldenObject();
        $this->policy($report)->mode = 'single-target';

        self::assertNotSame([], $this->validate($report));
    }

    public function testToolProbeCannotClaimPolicyMinors(): void
    {
        $report = $this->goldenObject();
        $this->firstPlan($report)->purpose = 'tool_probe';

        self::assertNotSame([], $this->validate($report));
    }

    public function testSupportedPolicyRejectsAProbeOnlyPlan(): void
    {
        $report = $this->goldenObject();
        $plan = $this->firstPlan($report);
        $plan->purpose = 'tool_probe';
        $plan->policy_minors = [];

        self::assertNotSame([], $this->validate($report));
    }

    public function testCompletedReportRejectsProbeOnlyActualInvocations(): void
    {
        $report = $this->goldenObject();
        $invocation = $this->firstInvocation($report);
        $invocation->purpose = 'tool_probe';
        $invocation->policy_minors = [];

        self::assertNotSame([], $this->validate($report));
    }

    public function testAnalysisInvocationCannotHaveAnEmptyPolicyPartition(): void
    {
        $report = $this->goldenObject();
        $this->firstPlan($report)->policy_minors = [];

        self::assertNotSame([], $this->validate($report));
    }

    #[DataProvider('machineSpecificExecutableCases')]
    public function testMachineSpecificExecutableEvidenceIsRejected(string $location): void
    {
        $report = $this->goldenObject();

        if ($location === 'adapter') {
            $adapter = $report->adapter ?? null;
            self::assertInstanceOf(\stdClass::class, $adapter);
            $adapter->executable = '/home/alice/bin/phpcs';
        } elseif ($location === 'plan') {
            $this->firstPlan($report)->executable = '/home/alice/bin/phpcs';
        } else {
            $this->firstInvocation($report)->executable = '/home/alice/bin/phpcs';
        }

        self::assertNotSame([], $this->validate($report));
    }

    /** @return iterable<string, array{string}> */
    public static function machineSpecificExecutableCases(): iterable
    {
        yield 'adapter executable' => ['adapter'];
        yield 'planned executable' => ['plan'];
        yield 'actual executable' => ['actual'];
    }

    #[DataProvider('unsafeArgumentCases')]
    public function testInvocationArgumentsRejectMachineSpecificPaths(string $argument): void
    {
        $report = $this->goldenObject();
        $this->firstPlan($report)->arguments = [$argument];
        $this->firstInvocation($report)->arguments = [$argument];

        self::assertNotSame([], $this->validate($report));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeArgumentCases(): iterable
    {
        yield 'POSIX absolute path' => ['--config=/home/alice/private.neon'];
        yield 'Windows absolute path' => ['--config=C:\\Users\\Alice\\private.neon'];
        yield 'Windows drive-relative path' => ['--config=C:private.neon'];
        yield 'Windows drive-relative response file' => ['@C:arguments.txt'];
        yield 'UNC path' => ['--config=\\\\server\\share\\private.neon'];
        yield 'parent traversal' => ['--config=../private.neon'];
        yield 'response file path' => ['@/home/alice/arguments.txt'];
        yield 'file URI' => ['file:///home/alice/private.neon'];
    }

    public function testStableExternalExecutableIdentityIsAccepted(): void
    {
        $report = $this->goldenObject();
        $adapter = $report->adapter ?? null;
        self::assertInstanceOf(\stdClass::class, $adapter);
        $adapter->executable = '<external>/phpcs';
        $this->firstPlan($report)->executable = '<external>/phpcs';
        $this->firstInvocation($report)->executable = '<external>/phpcs';

        self::assertSame([], $this->validate($report));
    }

    #[DataProvider('uriPathCases')]
    public function testUriPathsAreRejectedFromStableEvidence(string $uri): void
    {
        $report = $this->goldenObject();
        $adapter = $report->adapter ?? null;
        self::assertInstanceOf(\stdClass::class, $adapter);
        $adapter->executable = $uri;

        self::assertNotSame([], $this->validate($report));

        $report = $this->goldenObject();
        $this->firstFinding($report)->file = $uri;

        self::assertNotSame([], $this->validate($report));
    }

    /** @return iterable<string, array{string}> */
    public static function uriPathCases(): iterable
    {
        yield 'file URI' => ['file:///home/alice/project/src/Foo.php'];
        yield 'PHAR URI' => ['phar:///home/alice/tool.phar/src/Foo.php'];
    }

    #[DataProvider('nonExitedInvocationCases')]
    public function testCapabilityUnavailableRejectsProcessFailures(
        string $status,
        ?int $exitCode,
        ?int $signal,
    ): void {
        $report = $this->supportedUnavailableObject();
        $invocation = $this->firstInvocation($report);
        $invocation->status = $status;
        $invocation->exit_code = $exitCode;
        $invocation->signal = $signal;

        self::assertNotSame([], $this->validate($report));
    }

    /** @return iterable<string, array{string, int|null, int|null}> */
    public static function nonExitedInvocationCases(): iterable
    {
        yield 'start failure' => ['start_failed', null, null];
        yield 'timeout' => ['timed_out', null, null];
        yield 'signal' => ['signaled', null, 15];
        yield 'output limit' => ['output_limit_exceeded', null, null];
    }

    private function goldenObject(): \stdClass
    {
        $raw = file_get_contents(__DIR__ . '/../../fixtures/verification/findings.json');
        self::assertIsString($raw);

        /** @var mixed $report */
        $report = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $report);

        return $report;
    }

    private function supportedUnavailableObject(): \stdClass
    {
        $report = $this->goldenObject();
        $report->status = 'unavailable';
        $report->exit_code = 7;
        $report->summary = (object) [
            'invocation_count' => 1,
            'finding_count' => 0,
            'mapped_finding_count' => 0,
            'unmapped_finding_count' => 0,
            'mapping_count' => 0,
            'mapped_rule_count' => 0,
        ];
        $report->reason = (object) [
            'code' => 'adapter.capability_unavailable',
            'message' => 'The required adapter capability is unavailable.',
        ];
        $report->rule_contexts = [];
        $report->findings = [];

        return $report;
    }

    /**
     * @return list<string>
     */
    private function validate(\stdClass $report): array
    {
        return (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($report);
    }

    private function policy(\stdClass $report): \stdClass
    {
        $policy = $report->policy ?? null;
        self::assertInstanceOf(\stdClass::class, $policy);

        return $policy;
    }

    private function firstPlan(\stdClass $report): \stdClass
    {
        $plans = $this->policy($report)->planned_invocations ?? null;
        self::assertIsArray($plans);
        $first = $plans[0] ?? null;
        self::assertInstanceOf(\stdClass::class, $first);

        return $first;
    }

    private function firstInvocation(\stdClass $report): \stdClass
    {
        $invocations = $report->invocations ?? null;
        self::assertIsArray($invocations);
        $first = $invocations[0] ?? null;
        self::assertInstanceOf(\stdClass::class, $first);

        return $first;
    }

    private function firstFinding(\stdClass $report): \stdClass
    {
        $findings = $report->findings ?? null;
        self::assertIsArray($findings);
        $first = $findings[0] ?? null;
        self::assertInstanceOf(\stdClass::class, $first);

        return $first;
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Command\VerifyCommand;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use ModernPhpGuidelines\Tests\Support\FakeVerificationAdapter;
use ModernPhpGuidelines\Tests\Support\FixtureTreeSnapshot;
use ModernPhpGuidelines\Verification\AdapterOutcome;
use ModernPhpGuidelines\Verification\AdapterPlan;
use ModernPhpGuidelines\Verification\AdapterResult;
use ModernPhpGuidelines\Verification\EvidenceClass;
use ModernPhpGuidelines\Verification\InvocationPurpose;
use ModernPhpGuidelines\Verification\MappingStatus;
use ModernPhpGuidelines\Verification\PlannedVerificationInvocation;
use ModernPhpGuidelines\Verification\Process\ProcessState;
use ModernPhpGuidelines\Verification\ProjectionStatus;
use ModernPhpGuidelines\Verification\VerificationAdapterRegistry;
use ModernPhpGuidelines\Verification\VerificationFinding;
use ModernPhpGuidelines\Verification\VerificationInvocation;
use ModernPhpGuidelines\Verification\VerificationReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

final class VerifyCommandTest extends TestCase
{
    private const PROJECT = __DIR__ . '/../fixtures/projects/comparison-range';

    /** @return iterable<string, array{AdapterResult, int, string, int}> */
    public static function outcomeCases(): iterable
    {
        yield 'success' => [self::successResult(), ExitCode::SUCCESS, 'success', 1];
        yield 'findings' => [self::findingsResult(), ExitCode::VERIFICATION_FINDINGS, 'findings', 1];
        yield 'unavailable' => [self::unavailableResult(), ExitCode::ADAPTER_UNAVAILABLE, 'unavailable', 0];
        yield 'failed' => [self::failedResult(), ExitCode::ADAPTER_FAILED, 'failed', 1];
        yield 'unsupported policy' => [
            self::unsupportedPolicyResult(),
            ExitCode::POLICY_PROJECTION_UNSUPPORTED,
            'unsupported_policy',
            0,
        ];
    }

    /** @return iterable<string, array{list<VerificationInvocation>, string}> */
    public static function invalidProjectionCases(): iterable
    {
        yield 'overlap' => [[
            self::invocation(0, 'verification-1', ['8.2', '8.3']),
            self::invocation(0, 'verification-2', ['8.3', '8.4']),
        ], 'overlaps on PHP 8.3'];

        yield 'missing minor' => [[
            self::invocation(0, 'verification-1', ['8.2', '8.3']),
        ], 'does not exactly match the resolved policy'];

        yield 'reordered partition' => [[
            self::invocation(0, 'verification-1', ['8.4']),
            self::invocation(0, 'verification-2', ['8.2', '8.3']),
        ], 'does not exactly match the resolved policy'];
    }

    /** @return iterable<string, array{AdapterPlan, AdapterResult, string}> */
    public static function executionPlanMismatchCases(): iterable
    {
        $standardPlan = new AdapterPlan(
            ProjectionStatus::Supported,
            [self::plannedInvocation('verification-1', ['8.2', '8.3', '8.4'])],
            null,
        );

        yield 'changed arguments' => [
            $standardPlan,
            new AdapterResult(
                AdapterOutcome::Completed,
                ProjectionStatus::Supported,
                '1.0.0',
                [self::invocation(arguments: ['--different', '.'])],
                [],
                null,
            ),
            'does not match the prevalidated projection plan',
        ];

        yield 'changed executable' => [
            $standardPlan,
            new AdapterResult(
                AdapterOutcome::Completed,
                ProjectionStatus::Supported,
                '1.0.0',
                [self::invocation(executable: 'different-analyzer')],
                [],
                null,
            ),
            'does not match the prevalidated projection plan',
        ];

        yield 'changed projected minors' => [
            $standardPlan,
            new AdapterResult(
                AdapterOutcome::Completed,
                ProjectionStatus::Supported,
                '1.0.0',
                [self::invocation(policyMinors: ['8.2'])],
                [],
                null,
            ),
            'does not match the prevalidated projection plan',
        ];

        yield 'unplanned id' => [
            $standardPlan,
            new AdapterResult(
                AdapterOutcome::Completed,
                ProjectionStatus::Supported,
                '1.0.0',
                [self::invocation(id: 'verification-2')],
                [],
                null,
            ),
            'does not match the prevalidated projection plan',
        ];

        yield 'completed result omits a planned partition' => [
            new AdapterPlan(
                ProjectionStatus::Supported,
                [
                    self::plannedInvocation('verification-1', ['8.2', '8.3']),
                    self::plannedInvocation('verification-2', ['8.4']),
                ],
                null,
            ),
            new AdapterResult(
                AdapterOutcome::Completed,
                ProjectionStatus::Supported,
                '1.0.0',
                [self::invocation(policyMinors: ['8.2', '8.3'])],
                [],
                null,
            ),
            'must execute every planned invocation',
        ];

        yield 'execution changes projection status' => [
            $standardPlan,
            self::unavailableResult(),
            'changed the prevalidated projection status',
        ];
    }

    #[DataProvider('outcomeCases')]
    public function testEveryAdapterOutcomeIsCompleteSchemaValidAndImmutable(
        AdapterResult $adapterResult,
        int $expectedExitCode,
        string $expectedStatus,
        int $expectedVerificationCalls,
    ): void {
        $project = $this->projectRoot();
        $before = FixtureTreeSnapshot::capture($project);

        $adapter = new FakeVerificationAdapter('fake', $adapterResult);
        $tester = $this->testerForAdapter($adapter);
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'fake',
                '--executable' => 'fake-analyzer',
                '--project-root' => $project,
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame($expectedExitCode, $exitCode);
        self::assertSame('', $tester->getErrorOutput());
        self::assertStringEndsWith("\n", $tester->getDisplay());

        /** @var mixed $decoded */
        $decoded = json_decode($tester->getDisplay(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertSame([], (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($decoded));

        /** @var array{status: mixed, exit_code: mixed} $assoc */
        $assoc = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expectedStatus, $assoc['status']);
        self::assertSame($expectedExitCode, $assoc['exit_code']);
        self::assertSame($expectedVerificationCalls, $adapter->verificationCallCount());

        self::assertSame(
            $before,
            FixtureTreeSnapshot::capture($project),
            sprintf('The target fixture changed on the %s adapter path.', $expectedStatus),
        );
    }

    public function testFindingsKeepExactMappingsAndUnmappedEvidence(): void
    {
        $adapter = new FakeVerificationAdapter('fake', self::findingsResult());
        $tester = $this->testerForAdapter($adapter);
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'fake',
                '--executable' => 'fake-analyzer',
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
        /**
         * @var array{
         *     summary: array{
         *         invocation_count: int,
         *         finding_count: int,
         *         mapped_finding_count: int,
         *         unmapped_finding_count: int,
         *         mapping_count: int,
         *         mapped_rule_count: int,
         *     },
         *     findings: array{
         *         0: array{mapping_status: string, mapped_rule_ids: list<string>},
         *         1: array{mapping_status: string, mapped_rule_ids: list<string>},
         *     },
         *     rule_contexts: array{
         *         0: array{id: string, applicability: array{status: string}},
         *     },
         * } $report
         */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            'invocation_count' => 1,
            'finding_count' => 2,
            'mapped_finding_count' => 1,
            'unmapped_finding_count' => 1,
            'mapping_count' => 1,
            'mapped_rule_count' => 1,
        ], $report['summary']);

        $findings = $report['findings'];
        self::assertSame('mapped', $findings[0]['mapping_status']);
        self::assertSame(['language.dollar_brace_string_interpolation'], $findings[0]['mapped_rule_ids']);
        self::assertSame('unmapped', $findings[1]['mapping_status']);
        self::assertSame([], $findings[1]['mapped_rule_ids']);

        $contexts = $report['rule_contexts'];
        self::assertSame(['language.dollar_brace_string_interpolation'], array_column($contexts, 'id'));
        self::assertSame('deprecated_across_range', $contexts[0]['applicability']['status']);
    }

    public function testMappingRelationshipsRemainExactListsAndContextsAreDeduplicated(): void
    {
        $result = new AdapterResult(
            AdapterOutcome::Completed,
            ProjectionStatus::Supported,
            '1.0.0',
            [self::invocation(1)],
            [
                new VerificationFinding(
                    EvidenceClass::ExternalCompatibility,
                    ['verification-1'],
                    'Fake.Sniff.OneToMany',
                    'error',
                    '5',
                    'One external identifier maps to two project rules.',
                    'src/Example.php',
                    10,
                    1,
                    null,
                    MappingStatus::Mapped,
                    ['core.dynamic_properties', 'language.dollar_brace_string_interpolation'],
                ),
                new VerificationFinding(
                    EvidenceClass::DeprecationAnnotation,
                    ['verification-1'],
                    'Fake.Sniff.ManyToOne',
                    'warning',
                    null,
                    'A second external identifier maps to an existing project rule.',
                    'src/Example.php',
                    20,
                    1,
                    null,
                    MappingStatus::Mapped,
                    ['core.dynamic_properties'],
                ),
            ],
            null,
        );
        $tester = $this->tester($result);
        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $tester->run([
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
            '--json' => true,
        ], ['decorated' => false]));

        /**
         * @var array{
         *     summary: array{mapping_count: int, mapped_rule_count: int},
         *     findings: array{0: array{mapped_rule_ids: list<string>}, 1: array{mapped_rule_ids: list<string>}},
         *     rule_contexts: list<array{id: string}>,
         * } $report
         */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, $report['summary']['mapping_count']);
        self::assertSame(2, $report['summary']['mapped_rule_count']);
        self::assertSame(
            ['core.dynamic_properties', 'language.dollar_brace_string_interpolation'],
            $report['findings'][0]['mapped_rule_ids'],
        );
        self::assertSame(['core.dynamic_properties'], $report['findings'][1]['mapped_rule_ids']);
        self::assertSame(
            ['core.dynamic_properties', 'language.dollar_brace_string_interpolation'],
            array_column($report['rule_contexts'], 'id'),
        );
    }

    public function testFindingsJsonMatchesTheCanonicalGoldenByteForByte(): void
    {
        $adapter = new FakeVerificationAdapter('fake', self::findingsResult());
        $tester = $this->testerForAdapter($adapter);
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'fake',
                '--executable' => 'fake-analyzer',
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
        $golden = file_get_contents(__DIR__ . '/../fixtures/verification/findings.json');
        self::assertIsString($golden);
        self::assertSame($golden, $tester->getDisplay());
    }

    public function testJsonOutputIsByteIdenticalAcrossRuns(): void
    {
        $arguments = [
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
            '--json' => true,
        ];

        $first = $this->tester(self::findingsResult());
        $second = $this->tester(self::findingsResult());
        $first->run($arguments, ['capture_stderr_separately' => true, 'decorated' => false]);
        $second->run($arguments, ['capture_stderr_separately' => true, 'decorated' => false]);

        self::assertSame($first->getDisplay(), $second->getDisplay());
    }

    public function testExternalFormatterTagsRemainLiteralInJsonAndHumanOutput(): void
    {
        $message = 'Literal <error>tag</error> from the analyzer.';
        $result = new AdapterResult(
            AdapterOutcome::Completed,
            ProjectionStatus::Supported,
            '1.0.0',
            [self::invocation(1)],
            [new VerificationFinding(
                EvidenceClass::ExternalCompatibility,
                ['verification-1'],
                'Fake.Sniff.FormatterTag',
                'warning',
                '3',
                $message,
                'src/Example.php',
                30,
                1,
                null,
                MappingStatus::Unmapped,
                [],
            )],
            null,
        );
        $base = [
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
        ];

        $json = $this->tester($result);
        self::assertSame(
            ExitCode::VERIFICATION_FINDINGS,
            $json->run($base + ['--json' => true], ['decorated' => true]),
        );
        self::assertStringContainsString($message, $json->getDisplay());
        self::assertStringNotContainsString("\x1B", $json->getDisplay());

        $human = $this->tester($result);
        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $human->run($base, ['decorated' => true]));
        self::assertStringContainsString($message, $human->getDisplay());
        self::assertStringNotContainsString("\x1B", $human->getDisplay());
    }

    public function testIncompleteRangeSafeCoverageCannotBeClaimedAsAnExactProjection(): void
    {
        $project = $this->fixtureRoot('caret-8-2');
        $before = FixtureTreeSnapshot::capture($project);
        $adapter = new FakeVerificationAdapter('fake', self::findingsResult());
        $tester = $this->testerForAdapter($adapter);

        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'fake',
                '--executable' => 'fake-analyzer',
                '--project-root' => $project,
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::FAILURE, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "Error: internal error: Verification adapters cannot plan exact range-safe projection when policy coverage is incomplete.\n",
            $tester->getErrorOutput(),
        );
        self::assertSame($before, FixtureTreeSnapshot::capture($project));
        self::assertSame(0, $adapter->verificationCallCount());
    }

    public function testMultipleInvocationsMayPartitionThePolicyExactly(): void
    {
        $result = new AdapterResult(
            AdapterOutcome::Completed,
            ProjectionStatus::Supported,
            '1.0.0',
            [
                self::invocation(0, 'verification-2', ['8.4']),
                self::invocation(0, 'verification-1', ['8.2', '8.3']),
            ],
            [],
            null,
        );
        $tester = $this->tester($result);

        self::assertSame(ExitCode::SUCCESS, $tester->run([
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
            '--json' => true,
        ], ['capture_stderr_separately' => true, 'decorated' => false]));
        self::assertSame('', $tester->getErrorOutput());

        /**
         * @var array{
         *     invocations: array{
         *         0: array{id: string, policy_minors: list<string>},
         *         1: array{id: string, policy_minors: list<string>},
         *     },
         * } $report
         */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('verification-1', $report['invocations'][0]['id']);
        self::assertSame(['8.2', '8.3'], $report['invocations'][0]['policy_minors']);
        self::assertSame('verification-2', $report['invocations'][1]['id']);
        self::assertSame(['8.4'], $report['invocations'][1]['policy_minors']);
    }

    public function testFailedExecutionMayTruthfullyReportOnlyTheAttemptedPlanPartition(): void
    {
        $plan = new AdapterPlan(
            ProjectionStatus::Supported,
            [
                self::plannedInvocation('verification-1', ['8.2', '8.3']),
                self::plannedInvocation('verification-2', ['8.4']),
            ],
            null,
        );
        $result = new AdapterResult(
            AdapterOutcome::Failed,
            ProjectionStatus::Supported,
            null,
            [new VerificationInvocation(
                'verification-1',
                ['8.2', '8.3'],
                'fake-analyzer',
                ['--format=json', '.'],
                ProcessState::TimedOut,
                null,
            )],
            [],
            new VerificationReason(VerificationReason::PROCESS_TIMED_OUT, 'The first partition timed out.'),
        );
        $adapter = new FakeVerificationAdapter('fake', $result, $plan);
        $tester = $this->testerForAdapter($adapter);

        self::assertSame(ExitCode::ADAPTER_FAILED, $tester->run([
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
            '--json' => true,
        ], ['decorated' => false]));
        self::assertSame(1, $adapter->verificationCallCount());

        /**
         * @var array{
         *     policy: array{planned_invocations: list<array{id: string}>},
         *     invocations: list<array{id: string, status: string}>,
         * } $report
         */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['verification-1', 'verification-2'], array_column(
            $report['policy']['planned_invocations'],
            'id',
        ));
        self::assertSame([['id' => 'verification-1', 'status' => 'timed_out']], array_map(
            static fn(array $invocation): array => [
                'id' => $invocation['id'],
                'status' => $invocation['status'],
            ],
            $report['invocations'],
        ));
    }

    public function testToolProbeMayTruthfullyDiscoverAnUnavailableCapabilityBeforeAnalysis(): void
    {
        $plan = new AdapterPlan(
            ProjectionStatus::Supported,
            [
                self::plannedInvocation('verification-1', [], ['--version'], purpose: InvocationPurpose::ToolProbe),
                self::plannedInvocation('verification-2', ['8.2', '8.3', '8.4']),
            ],
            null,
        );
        $result = new AdapterResult(
            AdapterOutcome::Unavailable,
            ProjectionStatus::Supported,
            '3.10.3',
            [new VerificationInvocation(
                'verification-1',
                [],
                'fake-analyzer',
                ['--version'],
                ProcessState::Exited,
                0,
                purpose: InvocationPurpose::ToolProbe,
            )],
            [],
            new VerificationReason(
                VerificationReason::CAPABILITY_UNAVAILABLE,
                'The required fake analyzer capability is unavailable.',
            ),
        );
        $adapter = new FakeVerificationAdapter('fake', $result, $plan);
        $tester = $this->testerForAdapter($adapter);

        self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $tester->run([
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
            '--json' => true,
        ], ['capture_stderr_separately' => true, 'decorated' => false]));
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame(1, $adapter->verificationCallCount());

        /** @var array{adapter: array{tool_version: string}, policy: array{planned_invocations: list<array{purpose: string}>}, invocations: list<array{purpose: string}>, reason: array{code: string}} $report */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3.10.3', $report['adapter']['tool_version']);
        self::assertSame(['tool_probe', 'analysis'], array_column($report['policy']['planned_invocations'], 'purpose'));
        self::assertSame(['tool_probe'], array_column($report['invocations'], 'purpose'));
        self::assertSame(VerificationReason::CAPABILITY_UNAVAILABLE, $report['reason']['code']);
        /** @var mixed $decoded */
        $decoded = json_decode($tester->getDisplay(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertSame([], (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($decoded));
    }

    public function testSignaledExecutionIsPreservedAsFailureEvidence(): void
    {
        $result = new AdapterResult(
            AdapterOutcome::Failed,
            ProjectionStatus::Supported,
            '1.0.0',
            [new VerificationInvocation(
                'verification-1',
                ['8.2', '8.3', '8.4'],
                'fake-analyzer',
                ['--format=json', '.'],
                ProcessState::Signaled,
                null,
                15,
            )],
            [],
            new VerificationReason(VerificationReason::PROCESS_SIGNALED, 'The fake analyzer received signal 15.'),
        );
        $tester = $this->tester($result);

        self::assertSame(ExitCode::ADAPTER_FAILED, $tester->run([
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
            '--json' => true,
        ], ['capture_stderr_separately' => true, 'decorated' => false]));
        self::assertSame('', $tester->getErrorOutput());

        /** @var array{invocations: list<array{status: string, exit_code: mixed, signal: int}>, reason: array{code: string}} $report */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('signaled', $report['invocations'][0]['status']);
        self::assertNull($report['invocations'][0]['exit_code']);
        self::assertSame(15, $report['invocations'][0]['signal']);
        self::assertSame(VerificationReason::PROCESS_SIGNALED, $report['reason']['code']);
        /** @var mixed $decoded */
        $decoded = json_decode($tester->getDisplay(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertSame([], (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($decoded));
    }

    /** @param list<VerificationInvocation> $invocations */
    #[DataProvider('invalidProjectionCases')]
    public function testMultipleInvocationProjectionFailsClosed(
        array $invocations,
        string $expectedErrorFragment,
    ): void {
        $result = new AdapterResult(
            AdapterOutcome::Completed,
            ProjectionStatus::Supported,
            '1.0.0',
            $invocations,
            [],
            null,
        );
        $adapter = new FakeVerificationAdapter('fake', $result);
        $tester = $this->testerForAdapter($adapter);

        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'fake',
                '--executable' => 'fake-analyzer',
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::FAILURE, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString($expectedErrorFragment, $tester->getErrorOutput());
        self::assertSame(0, $adapter->verificationCallCount());
    }

    public function testPlanWithDifferentExecutableFailsBeforeVerification(): void
    {
        $plan = new AdapterPlan(
            ProjectionStatus::Supported,
            [self::plannedInvocation(
                'verification-1',
                ['8.2', '8.3', '8.4'],
                executable: 'different-analyzer',
            )],
            null,
        );

        $this->assertPlanRejectedBeforeVerification($plan, 'normalized executable identity');
    }

    #[DataProvider('unstablePlanArgumentCases')]
    public function testUnstablePlanArgumentsFailBeforeVerification(string $argument): void
    {
        $plan = new AdapterPlan(
            ProjectionStatus::Supported,
            [self::plannedInvocation('verification-1', ['8.2', '8.3', '8.4'], [$argument])],
            null,
        );

        $this->assertPlanRejectedBeforeVerification($plan, 'project-relative, cwd-based paths');
    }

    /** @return iterable<string, array{string}> */
    public static function unstablePlanArgumentCases(): iterable
    {
        yield 'absolute POSIX path' => ['/tmp/machine-specific.php'];
        yield 'absolute path in option' => ['--configuration=/tmp/phpstan.neon'];
        yield 'absolute Windows path' => ['C:\\machine\\phpcs.xml'];
        yield 'drive-relative Windows path' => ['C:machine\\phpcs.xml'];
        yield 'drive-relative Windows path in option' => ['--configuration=C:phpstan.neon'];
        yield 'drive-relative Windows response file' => ['@C:phpcs.args'];
        yield 'UNC path' => ['\\\\server\\share\\phpcs.xml'];
        yield 'response-file absolute path' => ['@/tmp/phpcs.args'];
        yield 'file URI path' => ['file:///tmp/phpcs.xml'];
        yield 'colon-separated option path' => ['--configuration:/tmp/phpstan.neon'];
        yield 'parent traversal' => ['../outside.php'];
        yield 'parent traversal in option' => ['--configuration=../phpstan.neon'];
    }

    #[DataProvider('executionPlanMismatchCases')]
    public function testExecutionResultMustMatchThePrevalidatedPlan(
        AdapterPlan $plan,
        AdapterResult $result,
        string $errorFragment,
    ): void {
        $adapter = new FakeVerificationAdapter('fake', $result, $plan);
        $tester = $this->testerForAdapter($adapter);

        $exitCode = $tester->run([
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
            '--json' => true,
        ], ['capture_stderr_separately' => true, 'decorated' => false]);

        self::assertSame(ExitCode::FAILURE, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString($errorFragment, $tester->getErrorOutput());
        self::assertSame(1, $adapter->verificationCallCount());
    }

    public function testHumanAndJsonCommunicateTheSameStatusAndCounts(): void
    {
        $base = [
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
        ];

        $human = $this->tester(self::findingsResult());
        $json = $this->tester(self::findingsResult());
        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $human->run($base, ['decorated' => false]));
        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $json->run($base + ['--json' => true], ['decorated' => false]));

        self::assertStringContainsString('Verification: findings (exit 6)', $human->getDisplay());
        self::assertStringContainsString('Planned invocations: 1', $human->getDisplay());
        self::assertStringContainsString('Invocations: 1', $human->getDisplay());
        self::assertStringContainsString('Findings: 2', $human->getDisplay());
        self::assertStringContainsString('mapped findings        1', $human->getDisplay());
        self::assertStringContainsString('unmapped findings      1', $human->getDisplay());

        /** @var array{status: string, policy: array{planned_invocations: list<mixed>}, summary: array{invocation_count: int, finding_count: int}} $report */
        $report = json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('findings', $report['status']);
        self::assertCount(1, $report['policy']['planned_invocations']);
        self::assertSame(1, $report['summary']['invocation_count']);
        self::assertSame(2, $report['summary']['finding_count']);
    }

    public function testUnknownAdapterIsInvalidInputWithEmptyStdout(): void
    {
        $tester = $this->tester(self::successResult());
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'not-registered',
                '--executable' => 'fake-analyzer',
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "Error: Unknown verification adapter \"not-registered\". Expected one of: fake.\n",
            $tester->getErrorOutput(),
        );
    }

    public function testMissingExecutableOptionIsInvalidInputWithEmptyStdout(): void
    {
        $tester = $this->tester(self::successResult());
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'fake',
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            "Error: --executable is required and must be a non-empty string.\n",
            $tester->getErrorOutput(),
        );
    }

    public function testMissingAdapterArgumentIsInvalidInputWithEmptyStdout(): void
    {
        $tester = $this->tester(self::successResult());
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                '--executable' => 'fake-analyzer',
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Not enough arguments', $tester->getErrorOutput());
        self::assertStringContainsString('adapter', $tester->getErrorOutput());
    }

    public function testExecutableOptionTokenWithoutValueIsInvalidInputWithEmptyStdout(): void
    {
        $tester = $this->tester(self::successResult());
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'fake',
                '--executable' => null,
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('requires a value', $tester->getErrorOutput());
    }

    #[DataProvider('invalidExecutableSelectionCases')]
    public function testAmbiguousExecutableSelectionIsInvalidInput(string $selected): void
    {
        $tester = $this->tester(self::successResult());
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'fake',
                '--executable' => $selected,
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('must be a stable path or PATH name', $tester->getErrorOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidExecutableSelectionCases(): iterable
    {
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
        yield 'drive only' => ['C:'];
        yield 'drive relative' => ['C:tools\\phpcs'];
        yield 'file URI' => ['file:///home/alice/phpcs'];
        yield 'PHAR URI' => ['phar:///home/alice/tool.phar'];
        yield 'reserved external identity' => ['<external>'];
        yield 'reserved external path' => ['<external>/phpcs'];
    }

    public function testProductionPlaceholderReportsMissingExecutableAsUnavailable(): void
    {
        $application = ApplicationFactory::create();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $tester = new ApplicationTester($application);

        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'phpcompatibility',
                '--executable' => '/definitely/not-installed/phpcs',
                '--project-root' => $this->projectRoot(),
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $exitCode);
        self::assertSame('', $tester->getErrorOutput());
        /**
         * @var array{
         *     status: string,
         *     adapter: array{executable: string},
         *     policy: array{projection_status: string},
         *     reason: array{code: string},
         * } $report
         */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unavailable', $report['status']);
        self::assertSame('<external>/phpcs', $report['adapter']['executable']);
        self::assertStringNotContainsString('/definitely/not-installed', $tester->getDisplay());
        self::assertSame('not_evaluated', $report['policy']['projection_status']);
        self::assertSame('adapter.executable_unavailable', $report['reason']['code']);
    }

    public function testProductionPlaceholderNeverExecutesAnExistingSelectedProgram(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('This non-execution sentinel uses a POSIX executable script.');
        }

        $temporaryDirectory = sys_get_temp_dir()
            . '/php-modern-guidelines-placeholder-'
            . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($temporaryDirectory, 0700));
        $selectedExecutable = $temporaryDirectory . '/must-not-run';
        $marker = $temporaryDirectory . '/executed';
        self::assertNotFalse(file_put_contents(
            $selectedExecutable,
            "#!/bin/sh\n: > " . escapeshellarg($marker) . "\n",
        ));
        self::assertTrue(chmod($selectedExecutable, 0700));

        try {
            $project = $this->projectRoot();
            $before = FixtureTreeSnapshot::capture($project);
            $application = ApplicationFactory::create();
            $application->setAutoExit(false);
            $application->setCatchExceptions(false);
            $tester = new ApplicationTester($application);

            $exitCode = $tester->run(
                [
                    'command' => 'verify',
                    'adapter' => 'phpcompatibility',
                    '--executable' => $selectedExecutable,
                    '--project-root' => $project,
                    '--json' => true,
                ],
                ['capture_stderr_separately' => true, 'decorated' => false],
            );

            self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $exitCode);
            self::assertSame('', $tester->getErrorOutput());
            /** @var array{reason: array{code: string}} $report */
            $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('adapter.capability_unavailable', $report['reason']['code']);
            self::assertFileDoesNotExist($marker);
            self::assertSame($before, FixtureTreeSnapshot::capture($project));
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
            if (is_file($selectedExecutable)) {
                unlink($selectedExecutable);
            }
            if (is_dir($temporaryDirectory)) {
                rmdir($temporaryDirectory);
            }
        }
    }

    private function tester(AdapterResult $result): ApplicationTester
    {
        return $this->testerForAdapter(new FakeVerificationAdapter('fake', $result));
    }

    private function testerForAdapter(FakeVerificationAdapter $adapter): ApplicationTester
    {
        $application = new Application('php-modern-guidelines-test');
        $application->add(new VerifyCommand(new VerificationAdapterRegistry([
            $adapter,
        ])));
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return new ApplicationTester($application);
    }

    private function assertPlanRejectedBeforeVerification(AdapterPlan $plan, string $errorFragment): void
    {
        $adapter = new FakeVerificationAdapter('fake', self::successResult(), $plan);
        $tester = $this->testerForAdapter($adapter);

        $exitCode = $tester->run([
            'command' => 'verify',
            'adapter' => 'fake',
            '--executable' => 'fake-analyzer',
            '--project-root' => $this->projectRoot(),
            '--json' => true,
        ], ['capture_stderr_separately' => true, 'decorated' => false]);

        self::assertSame(ExitCode::FAILURE, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString($errorFragment, $tester->getErrorOutput());
        self::assertSame(0, $adapter->verificationCallCount());
    }

    private function projectRoot(): string
    {
        $path = realpath(self::PROJECT);
        self::assertIsString($path);

        return $path;
    }

    private function fixtureRoot(string $fixture): string
    {
        $path = realpath(__DIR__ . '/../fixtures/projects/' . $fixture);
        self::assertIsString($path);

        return $path;
    }

    /**
     * @param list<string> $policyMinors
     * @param list<string> $arguments
     */
    private static function invocation(
        int $exitCode = 0,
        string $id = 'verification-1',
        array $policyMinors = ['8.2', '8.3', '8.4'],
        string $executable = 'fake-analyzer',
        array $arguments = ['--format=json', '.'],
    ): VerificationInvocation {
        return new VerificationInvocation(
            $id,
            $policyMinors,
            $executable,
            $arguments,
            ProcessState::Exited,
            $exitCode,
        );
    }

    /**
     * @param list<string> $policyMinors
     * @param list<string> $arguments
     */
    private static function plannedInvocation(
        string $id,
        array $policyMinors,
        array $arguments = ['--format=json', '.'],
        string $executable = 'fake-analyzer',
        InvocationPurpose $purpose = InvocationPurpose::Analysis,
    ): PlannedVerificationInvocation {
        return new PlannedVerificationInvocation(
            $id,
            $policyMinors,
            $executable,
            $arguments,
            $purpose,
        );
    }

    private static function successResult(): AdapterResult
    {
        return new AdapterResult(
            AdapterOutcome::Completed,
            ProjectionStatus::Supported,
            '1.0.0',
            [self::invocation()],
            [],
            null,
        );
    }

    private static function findingsResult(): AdapterResult
    {
        return new AdapterResult(
            AdapterOutcome::Completed,
            ProjectionStatus::Supported,
            '1.0.0',
            [self::invocation(1)],
            [
                new VerificationFinding(
                    EvidenceClass::ExternalCompatibility,
                    ['verification-1'],
                    'Fake.Sniff.Unmapped',
                    'warning',
                    '3',
                    'Unmapped fake finding.',
                    'src/Example.php',
                    20,
                    1,
                    null,
                    MappingStatus::Unmapped,
                    [],
                ),
                new VerificationFinding(
                    EvidenceClass::ExternalCompatibility,
                    ['verification-1'],
                    'Fake.Sniff.Mapped',
                    'error',
                    '5',
                    'Mapped fake finding.',
                    'src/Example.php',
                    10,
                    3,
                    null,
                    MappingStatus::Mapped,
                    ['language.dollar_brace_string_interpolation'],
                ),
            ],
            null,
        );
    }

    private static function unavailableResult(): AdapterResult
    {
        return new AdapterResult(
            AdapterOutcome::Unavailable,
            ProjectionStatus::NotEvaluated,
            null,
            [],
            [],
            new VerificationReason(VerificationReason::CAPABILITY_UNAVAILABLE, 'The fake adapter is unavailable.'),
        );
    }

    private static function failedResult(): AdapterResult
    {
        return new AdapterResult(
            AdapterOutcome::Failed,
            ProjectionStatus::Supported,
            '1.0.0',
            [new VerificationInvocation(
                'verification-1',
                ['8.2', '8.3', '8.4'],
                'fake-analyzer',
                ['--format=json', '.'],
                ProcessState::TimedOut,
                null,
            )],
            [],
            new VerificationReason(VerificationReason::PROCESS_TIMED_OUT, 'The fake adapter timed out.'),
        );
    }

    private static function unsupportedPolicyResult(): AdapterResult
    {
        return new AdapterResult(
            AdapterOutcome::UnsupportedPolicy,
            ProjectionStatus::Unsupported,
            null,
            [],
            [],
            new VerificationReason(
                VerificationReason::POLICY_PROJECTION_UNSUPPORTED,
                'The fake adapter cannot represent the resolved policy exactly.',
            ),
        );
    }
}

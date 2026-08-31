<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use ModernPhpGuidelines\Tests\Support\FixtureTreeSnapshot;
use ModernPhpGuidelines\Verification\Process\NativeProcessRunner;
use ModernPhpGuidelines\Verification\VerificationReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Drives the real, pinned PHP_CodeSniffer + PHPCompatibility installation named by
 * `MODERN_PHP_GUIDELINES_PHPCS` against the committed fixture trees. This class is deliberately not in
 * the `process-isolation` group: the two groups stay disjoint so each CI command has exactly one
 * precondition, even though every case here also executes a real child process.
 */
#[Group('real-analyzer')]
final class VerifyPhpCompatibilityAnalyzerTest extends TestCase
{
    private const FINDINGS_PROJECT_PATH = __DIR__ . '/../fixtures/verification/projects/phpcompatibility-findings';
    private const CLEAN_PROJECT_PATH = __DIR__ . '/../fixtures/verification/projects/phpcompatibility-clean';
    private const OR_CONSTRAINT_PROJECT_PATH = __DIR__ . '/../fixtures/projects/or-constraint';

    private string $executable = '';

    private string $findingsProject = '';

    private string $cleanProject = '';

    /** @return iterable<string, array{string, string, ?string, string}> */
    public static function schemaValidationCases(): iterable
    {
        yield 'success: the clean project' => [self::CLEAN_PROJECT_PATH, 'real', null, 'success'];
        yield 'findings: the committed findings tree' => [self::FINDINGS_PROJECT_PATH, 'real', null, 'findings'];
        yield 'unavailable: a missing executable' => [self::FINDINGS_PROJECT_PATH, 'missing', null, 'unavailable'];
        yield 'unavailable: an existing non-phpcs executable' => [
            self::FINDINGS_PROJECT_PATH, 'non-phpcs', null, 'unavailable',
        ];
        yield 'unsupported policy: a non-contiguous allowed set' => [
            self::OR_CONSTRAINT_PROJECT_PATH, 'real', null, 'unsupported_policy',
        ];
    }

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The pinned analyzer used by this group targets POSIX hosts only.');
        }

        if (!NativeProcessRunner::isSupportedOnCurrentPlatform()) {
            self::markTestSkipped(
                'This case executes a child process through the core executor, which requires operational '
                . 'Linux user/PID-namespace isolation.',
            );
        }

        $executable = getenv('MODERN_PHP_GUIDELINES_PHPCS');
        if (!is_string($executable)
            || $executable === ''
            || !is_file($executable)
            || !is_executable($executable)) {
            self::markTestSkipped(
                'MODERN_PHP_GUIDELINES_PHPCS must name an existing, executable, pinned PHP_CodeSniffer binary.',
            );
        }
        $this->executable = $executable;

        $this->findingsProject = self::realProjectRoot(self::FINDINGS_PROJECT_PATH);
        $this->cleanProject = self::realProjectRoot(self::CLEAN_PROJECT_PATH);
    }

    public function testFindingsPathIsCompleteDeterministicAndMapped(): void
    {
        $before = FixtureTreeSnapshot::capture($this->findingsProject);

        [$exitCode, $display] = $this->runForDisplay($this->findingsProject);
        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
        self::assertStringNotContainsString($this->findingsProject, $display);
        self::assertStringNotContainsString($this->executable, $display);

        /**
         * @var array{
         *     status: string,
         *     adapter: array{tool_version: string|null},
         *     summary: array{
         *         invocation_count: int,
         *         finding_count: int,
         *         mapped_finding_count: int,
         *         unmapped_finding_count: int,
         *         mapping_count: int,
         *         mapped_rule_count: int,
         *     },
         *     rule_contexts: list<array{id: string}>,
         *     findings: list<array{external_rule_id: string, file: string|null, mapping_status: string, mapped_rule_ids: list<string>}>,
         * } $report
         */
        $report = self::decode($display);

        self::assertSame('findings', $report['status']);
        self::assertSame(
            [
                'invocation_count' => 3,
                'finding_count' => 17,
                'mapped_finding_count' => 14,
                'unmapped_finding_count' => 3,
                'mapping_count' => 14,
                'mapped_rule_count' => 8,
            ],
            $report['summary'],
        );

        $ids = [];
        foreach ($report['findings'] as $finding) {
            $ids[] = $finding['external_rule_id'];
        }

        $expectedIds = [
            'PHPCompatibility.Classes.NewTypedConstants.Found',
            'PHPCompatibility.FunctionDeclarations.RemovedImplicitlyNullableParam.Deprecated',
            'PHPCompatibility.FunctionUse.NewFunctions.array_allFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_anyFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_findFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_find_keyFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_firstFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_lastFound',
            'PHPCompatibility.FunctionUse.NewFunctions.json_validateFound',
            'PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated',
            'PHPCompatibility.FunctionUse.RemovedFunctions.splitDeprecatedRemoved',
            'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_decodeDeprecated',
            'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_encodeDeprecated',
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.mysqli_reconnectRemoved',
            'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax',
            'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax',
        ];

        // (a) the sorted set of external rule ids: the 16 verified ids (13 mapped + 3 unmapped).
        $sortedSet = array_values(array_unique($ids));
        sort($sortedSet, SORT_STRING);
        self::assertSame($expectedIds, $sortedSet);

        // (b) the sorted 17-element multiset: the same set with the dollar-brace expression syntax id
        // appearing twice — once from mapped_findings.php, once from duplicate_findings.php — which are
        // different files and therefore different sortKey()s that dedupe must not collapse.
        $expectedMultiset = $expectedIds;
        $expectedMultiset[] = 'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax';
        sort($expectedMultiset, SORT_STRING);
        $sortedMultiset = $ids;
        sort($sortedMultiset, SORT_STRING);
        self::assertSame($expectedMultiset, $sortedMultiset);

        /** @var array<string, list<list<string>>> $mappedRuleIdsBySniffId */
        $mappedRuleIdsBySniffId = [];
        foreach ($report['findings'] as $finding) {
            $mappedRuleIdsBySniffId[$finding['external_rule_id']][] = $finding['mapped_rule_ids'];
        }

        foreach (['array_findFound', 'array_find_keyFound', 'array_anyFound', 'array_allFound'] as $suffix) {
            foreach ($mappedRuleIdsBySniffId['PHPCompatibility.FunctionUse.NewFunctions.' . $suffix] as $mapped) {
                self::assertSame(['core.array_find_functions'], $mapped);
            }
        }
        foreach (['array_firstFound', 'array_lastFound'] as $suffix) {
            foreach ($mappedRuleIdsBySniffId['PHPCompatibility.FunctionUse.NewFunctions.' . $suffix] as $mapped) {
                self::assertSame(['core.array_first_last'], $mapped);
            }
        }
        foreach (
            $mappedRuleIdsBySniffId['PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax'] as $mapped
        ) {
            self::assertSame(['language.dollar_brace_string_interpolation'], $mapped);
        }
        foreach (
            $mappedRuleIdsBySniffId['PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax'] as $mapped
        ) {
            self::assertSame(['language.dollar_brace_string_interpolation'], $mapped);
        }

        foreach ($report['findings'] as $finding) {
            if ($finding['mapping_status'] === 'unmapped') {
                self::assertSame([], $finding['mapped_rule_ids']);
            }

            $file = $finding['file'];
            self::assertIsString($file);
            self::assertStringStartsWith('src/', $file);
        }

        $ruleContextIds = [];
        foreach ($report['rule_contexts'] as $context) {
            $ruleContextIds[] = $context['id'];
        }
        self::assertSame(
            [
                'core.array_find_functions',
                'core.array_first_last',
                'core.json_validate',
                'extension.curl_close',
                'extension.mysqli_driver_reconnect',
                'language.dollar_brace_string_interpolation',
                'language.implicitly_nullable_parameter_types',
                'language.typed_class_constants',
            ],
            $ruleContextIds,
        );

        $toolVersion = $report['adapter']['tool_version'];
        self::assertIsString($toolVersion);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $toolVersion);

        self::assertSame($before, FixtureTreeSnapshot::capture($this->findingsProject));
    }

    public function testCleanProjectCompletesWithoutFindings(): void
    {
        $before = FixtureTreeSnapshot::capture($this->cleanProject);

        [$exitCode, $report] = $this->verifyReport($this->cleanProject);
        /**
         * @var array{
         *     status: string,
         *     invocations: list<array{status: string, exit_code: int|null}>,
         *     summary: array{finding_count: int},
         * } $report
         */
        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('success', $report['status']);
        self::assertSame(0, $report['summary']['finding_count']);
        self::assertCount(3, $report['invocations']);
        foreach ($report['invocations'] as $invocation) {
            self::assertSame('exited', $invocation['status']);
            self::assertSame(0, $invocation['exit_code']);
        }

        self::assertSame($before, FixtureTreeSnapshot::capture($this->cleanProject));
    }

    public function testNarrowerPolicyChangesTheProjectionAndTheFindings(): void
    {
        [, $narrow] = $this->verifyReport($this->findingsProject, '8.5.*');
        /**
         * @var array{
         *     policy: array{planned_invocations: list<array{arguments: list<string>}>},
         *     findings: list<array{external_rule_id: string}>,
         * } $narrow
         */
        self::assertSame('8.5', $narrow['policy']['planned_invocations'][2]['arguments'][3]);
        $narrowIds = [];
        foreach ($narrow['findings'] as $finding) {
            $narrowIds[] = $finding['external_rule_id'];
        }
        self::assertContains('PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated', $narrowIds);
        foreach ($narrowIds as $id) {
            self::assertStringNotContainsString('NewFunctions', $id);
        }

        [, $wide] = $this->verifyReport($this->findingsProject, '>=8.2 <8.5');
        /**
         * @var array{
         *     policy: array{planned_invocations: list<array{arguments: list<string>}>},
         *     findings: list<array{external_rule_id: string}>,
         * } $wide
         */
        self::assertSame('8.2-8.4', $wide['policy']['planned_invocations'][2]['arguments'][3]);
        $wideIds = [];
        foreach ($wide['findings'] as $finding) {
            $wideIds[] = $finding['external_rule_id'];
        }
        self::assertNotContains('PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated', $wideIds);
        self::assertContains('PHPCompatibility.FunctionUse.NewFunctions.array_findFound', $wideIds);

        // The two projections above are driven entirely by the --php override, never by the PHP version
        // the CLI process itself happens to run under: this is the "local runtime is never substituted"
        // proof, alongside the two-axis proof that a narrower or wider --php changes both the projected
        // argv and the resulting findings.
    }

    public function testUnsupportedPolicyNeverRunsTheAnalyzer(): void
    {
        $projectRoot = self::realProjectRoot(self::OR_CONSTRAINT_PROJECT_PATH);
        $before = FixtureTreeSnapshot::capture($projectRoot);

        [$exitCode, $report] = $this->verifyReport($projectRoot);
        /**
         * @var array{
         *     status: string,
         *     reason: array{code: string},
         *     invocations: list<mixed>,
         *     policy: array{planned_invocations: list<mixed>},
         * } $report
         */
        self::assertSame(ExitCode::POLICY_PROJECTION_UNSUPPORTED, $exitCode);
        self::assertSame('unsupported_policy', $report['status']);
        self::assertSame(VerificationReason::POLICY_PROJECTION_UNSUPPORTED, $report['reason']['code']);
        self::assertSame([], $report['invocations']);
        self::assertSame([], $report['policy']['planned_invocations']);

        self::assertSame($before, FixtureTreeSnapshot::capture($projectRoot));
    }

    public function testMissingExecutableIsUnavailable(): void
    {
        $before = FixtureTreeSnapshot::capture($this->findingsProject);

        [$exitCode, $report] = $this->verifyReport(
            $this->findingsProject,
            null,
            '/definitely/not-installed/phpcs',
        );
        /**
         * @var array{
         *     status: string,
         *     adapter: array{executable: string},
         *     policy: array{projection_status: string},
         *     reason: array{code: string},
         * } $report
         */
        self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $exitCode);
        self::assertSame('unavailable', $report['status']);
        self::assertSame('<external>/phpcs', $report['adapter']['executable']);
        self::assertSame('not_evaluated', $report['policy']['projection_status']);
        self::assertSame(VerificationReason::EXECUTABLE_UNAVAILABLE, $report['reason']['code']);

        self::assertSame($before, FixtureTreeSnapshot::capture($this->findingsProject));
    }

    public function testExistingNonPhpcsExecutableIsCapabilityUnavailable(): void
    {
        $before = FixtureTreeSnapshot::capture($this->findingsProject);

        [$exitCode, $report] = $this->verifyReport(
            $this->findingsProject,
            null,
            self::existingNonPhpcsExecutable(),
        );
        /**
         * @var array{
         *     status: string,
         *     adapter: array{tool_version: string|null},
         *     reason: array{code: string},
         *     invocations: list<array{status: string}>,
         * } $report
         */
        self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $exitCode);
        self::assertSame('unavailable', $report['status']);
        self::assertSame(VerificationReason::CAPABILITY_UNAVAILABLE, $report['reason']['code']);
        self::assertCount(1, $report['invocations']);
        self::assertSame('exited', $report['invocations'][0]['status']);
        self::assertNull($report['adapter']['tool_version']);

        self::assertSame($before, FixtureTreeSnapshot::capture($this->findingsProject));
    }

    public function testHumanAndJsonReportTheSameStatusAndCounts(): void
    {
        $humanTester = self::tester();
        $humanExitCode = $humanTester->run(
            [
                'command' => 'verify',
                'adapter' => 'phpcompatibility',
                '--executable' => $this->executable,
                '--project-root' => $this->findingsProject,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );
        $human = $humanTester->getDisplay();

        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $humanExitCode);
        self::assertStringContainsString('Verification: findings (exit 6)', $human);
        self::assertStringContainsString('Planned invocations: 3', $human);
        self::assertStringContainsString('Invocations: 3', $human);
        self::assertStringContainsString('Findings: 17', $human);
        self::assertStringContainsString('mapped findings        14', $human);
        self::assertStringContainsString('unmapped findings      3', $human);

        [$jsonExitCode, $report] = $this->verifyReport($this->findingsProject);
        /** @var array{status: string, exit_code: int, summary: array{finding_count: int}} $report */
        self::assertSame($humanExitCode, $jsonExitCode);
        self::assertSame('findings', $report['status']);
        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $report['exit_code']);
        self::assertSame(17, $report['summary']['finding_count']);
    }

    public function testJsonOutputIsByteIdenticalAcrossTwoRuns(): void
    {
        $before = FixtureTreeSnapshot::capture($this->findingsProject);

        [, $firstDisplay] = $this->runForDisplay($this->findingsProject);
        [, $secondDisplay] = $this->runForDisplay($this->findingsProject);

        self::assertSame($firstDisplay, $secondDisplay);
        self::assertSame($before, FixtureTreeSnapshot::capture($this->findingsProject));
    }

    #[DataProvider('schemaValidationCases')]
    public function testSchemaValidatesEveryRealAnalyzerOutcome(
        string $projectRoot,
        string $executableSelector,
        ?string $phpOverride,
        string $expectedStatus,
    ): void {
        $executable = match ($executableSelector) {
            'real' => $this->executable,
            'missing' => '/definitely/not-installed/phpcs',
            'non-phpcs' => self::existingNonPhpcsExecutable(),
            default => self::fail('Unknown executable selector: ' . $executableSelector),
        };

        [, $display] = $this->runForDisplay(self::realProjectRoot($projectRoot), $phpOverride, $executable);

        $tree = json_decode($display, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $tree);
        self::assertSame([], (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($tree));

        /** @var array{status: string} $report */
        $report = self::decode($display);
        self::assertSame($expectedStatus, $report['status']);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function verifyReport(string $projectRoot, ?string $phpOverride = null, ?string $executable = null): array
    {
        [$exitCode, $display] = $this->runForDisplay($projectRoot, $phpOverride, $executable);

        return [$exitCode, self::decode($display)];
    }

    /** @return array{0: int, 1: string} */
    private function runForDisplay(string $projectRoot, ?string $phpOverride = null, ?string $executable = null): array
    {
        /** @var array<string, bool|string> $arguments */
        $arguments = [
            'command' => 'verify',
            'adapter' => 'phpcompatibility',
            '--executable' => $executable ?? $this->executable,
            '--project-root' => $projectRoot,
            '--json' => true,
        ];
        if ($phpOverride !== null) {
            $arguments['--php'] = $phpOverride;
        }

        $tester = self::tester();
        $exitCode = $tester->run($arguments, ['capture_stderr_separately' => true, 'decorated' => false]);

        self::assertSame('', $tester->getErrorOutput());
        $display = $tester->getDisplay();
        self::assertStringEndsWith("\n", $display);

        $tree = json_decode($display, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $tree);
        self::assertSame([], (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($tree));

        return [$exitCode, $display];
    }

    /** @return array<string, mixed> */
    private static function decode(string $display): array
    {
        /** @var array<string, mixed> $report */
        $report = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        return $report;
    }

    private static function tester(): ApplicationTester
    {
        $application = ApplicationFactory::create();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return new ApplicationTester($application);
    }

    private static function existingNonPhpcsExecutable(): string
    {
        foreach (['/usr/bin/true', '/bin/true'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        self::fail('Neither /usr/bin/true nor /bin/true exists on this host.');
    }

    private static function realProjectRoot(string $path): string
    {
        $resolved = realpath($path);
        self::assertIsString($resolved);

        return $resolved;
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Command\VerifyCommand;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use ModernPhpGuidelines\Tests\Support\FixtureTreeSnapshot;
use ModernPhpGuidelines\Verification\Adapter\PhpCompatibilityAdapter;
use ModernPhpGuidelines\Verification\Process\NativeProcessRunner;
use ModernPhpGuidelines\Verification\VerificationAdapterRegistry;
use ModernPhpGuidelines\Verification\VerificationReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The complete outcome decision table (§6 of the work order) proven through the real ADR-008 executor
 * and the real process runner, driven by the committed stub analyzer (`tests/fixtures/verification/stub`).
 * No src/ execution seam exists for a test double anywhere on this path: every case constructs the real
 * `PhpCompatibilityAdapter` and lets it run the committed stub as a genuine child process, so this whole
 * class executes a child process and carries the process-isolation group.
 */
#[Group('process-isolation')]
final class VerifyPhpCompatibilityStubTest extends TestCase
{
    private const SHORT_TIMEOUT_MILLISECONDS = 1200;

    /** @return iterable<string, array{string, string, ?int, int, string, string, string, string, bool}> */
    public static function processLifecycleFailureCases(): iterable
    {
        $timedOutMessage = 'PHP_CodeSniffer did not finish within the planned timeout.';
        $signaledMessage = 'PHP_CodeSniffer was terminated by a signal.';
        $outputLimitMessage = 'PHP_CodeSniffer exceeded the bounded output capture.';

        yield 'row 5: version probe times out' => [
            'version', 'sleep', self::SHORT_TIMEOUT_MILLISECONDS, 1,
            'timed_out', VerificationReason::PROCESS_TIMED_OUT, $timedOutMessage,
            'verification-1', false,
        ];
        yield 'row 6: version probe is terminated by a signal' => [
            'version', 'signal', null, 1,
            'signaled', VerificationReason::PROCESS_SIGNALED, $signaledMessage,
            'verification-1', false,
        ];
        yield 'row 7: version probe exceeds the bounded output capture' => [
            'version', 'flood', null, 1,
            'output_limit_exceeded', VerificationReason::OUTPUT_LIMIT_EXCEEDED, $outputLimitMessage,
            'verification-1', false,
        ];
        yield 'row 12: standards probe times out' => [
            'standards', 'sleep', self::SHORT_TIMEOUT_MILLISECONDS, 2,
            'timed_out', VerificationReason::PROCESS_TIMED_OUT, $timedOutMessage,
            'verification-2', true,
        ];
        yield 'row 13: standards probe is terminated by a signal' => [
            'standards', 'signal', null, 2,
            'signaled', VerificationReason::PROCESS_SIGNALED, $signaledMessage,
            'verification-2', true,
        ];
        yield 'row 14: standards probe exceeds the bounded output capture' => [
            'standards', 'flood', null, 2,
            'output_limit_exceeded', VerificationReason::OUTPUT_LIMIT_EXCEEDED, $outputLimitMessage,
            'verification-2', true,
        ];
        yield 'row 19: analysis invocation times out' => [
            'analysis', 'sleep', self::SHORT_TIMEOUT_MILLISECONDS, 3,
            'timed_out', VerificationReason::PROCESS_TIMED_OUT, $timedOutMessage,
            'verification-3', true,
        ];
        yield 'row 20: analysis invocation is terminated by a signal' => [
            'analysis', 'signal', null, 3,
            'signaled', VerificationReason::PROCESS_SIGNALED, $signaledMessage,
            'verification-3', true,
        ];
        yield 'row 21: analysis invocation exceeds the bounded output capture' => [
            'analysis', 'flood', null, 3,
            'output_limit_exceeded', VerificationReason::OUTPUT_LIMIT_EXCEEDED, $outputLimitMessage,
            'verification-3', true,
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function standardsNotRegisteredCases(): iterable
    {
        yield 'row 16a: a realistic registered-standards line naming the confusable PHPCSUtils' => [
            'The installed coding standards are MySource, PEAR, PHPCSUtils, PSR1, PSR2, PSR12, Squiz and Zend',
        ];
        yield 'row 16b: a standard name that merely contains PHPCompatibility as a substring' => [
            'The only coding standard installed is PHPCompatibilityFoo',
        ];
    }

    /** @return iterable<string, array{?string, ?int, string, ?string}> */
    public static function analysisStructuralFailureCases(): iterable
    {
        $structureMessage = 'The PHP_CodeSniffer JSON report does not have the expected structure.';
        $fileMessage = 'The PHP_CodeSniffer JSON report named a file that cannot be expressed relative to the '
            . 'project root.';

        yield 'row 23: stdout is not decodable JSON' => [
            'not json',
            null,
            'PHP_CodeSniffer did not produce a parseable JSON report.',
            null,
        ];

        yield 'row 24: JSON decodes but is not the phpcs report shape' => [
            '{"totals":{},"files":[]}',
            null,
            $structureMessage,
            null,
        ];

        yield 'row 25a: a files key cannot be expressed relative to the project root' => [
            json_encode([
                'totals' => ['errors' => 0, 'warnings' => 1, 'fixable' => 0],
                'files' => ['/etc/passwd' => ['errors' => 0, 'warnings' => 1, 'messages' => []]],
            ], JSON_THROW_ON_ERROR),
            null,
            $fileMessage,
            '/etc/passwd',
        ];

        yield 'row 25b: a files key contains a raw control byte' => [
            json_encode([
                'totals' => ['errors' => 0, 'warnings' => 1, 'fixable' => 0],
                'files' => ["src/a\x01b.php" => ['errors' => 0, 'warnings' => 1, 'messages' => []]],
            ], JSON_THROW_ON_ERROR),
            null,
            $fileMessage,
            null,
        ];

        yield 'row 26: a message has no usable source or message' => [
            json_encode([
                'totals' => ['errors' => 0, 'warnings' => 1, 'fixable' => 0],
                'files' => ['src/Foo.php' => [
                    'errors' => 0,
                    'warnings' => 1,
                    'messages' => [
                        [
                            'message' => 'Something happened.',
                            'source' => '',
                            'severity' => 5,
                            'type' => 'WARNING',
                            'line' => 1,
                            'column' => 1,
                        ],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR),
            null,
            'The PHP_CodeSniffer JSON report contains a message without a usable identifier or text.',
            null,
        ];

        yield 'row 27: per-file counts do not match the published messages' => [
            json_encode([
                'totals' => ['errors' => 0, 'warnings' => 1, 'fixable' => 0],
                'files' => ['src/Foo.php' => ['errors' => 0, 'warnings' => 1, 'messages' => []]],
            ], JSON_THROW_ON_ERROR),
            null,
            'The PHP_CodeSniffer JSON report is internally inconsistent: its per-file counts do not match '
            . 'the messages it published.',
            null,
        ];

        yield 'row 28: a non-zero analysis status with no published finding' => [
            null,
            1,
            'PHP_CodeSniffer reported a non-zero analysis status without publishing any finding.',
            null,
        ];
    }

    protected function setUp(): void
    {
        self::requireProcessIsolation();
        self::assertTrue(is_executable(self::stub()));
    }

    public function testRow4SelectedExecutableThatCannotStartRecordsStartFailed(): void
    {
        $this->withProject(function (string $root): void {
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::notAProgram(), null);

            self::assertSame(ExitCode::ADAPTER_FAILED, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     adapter: array{executable: string},
             *     reason: array{code: string, message: string},
             *     invocations: list<array{id: string, status: string, exit_code: int|null, signal: int|null}>,
             *     summary: array{finding_count: int},
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('failed', $report['status']);
            self::assertSame('<external>/not-a-program', $report['adapter']['executable']);
            self::assertSame(VerificationReason::PROCESS_START_FAILED, $report['reason']['code']);
            self::assertSame(
                'The selected PHP_CodeSniffer executable could not be started.',
                $report['reason']['message'],
            );
            self::assertCount(1, $report['invocations']);
            self::assertSame('start_failed', $report['invocations'][0]['status']);
            self::assertNull($report['invocations'][0]['exit_code']);
            self::assertNull($report['invocations'][0]['signal']);
            self::assertSame(0, $report['summary']['finding_count']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    #[DataProvider('processLifecycleFailureCases')]
    public function testProcessLifecycleFailuresAreReportedAtTheFailingStage(
        string $stage,
        string $mode,
        ?int $timeoutMilliseconds,
        int $expectedInvocationCount,
        string $expectedStatus,
        string $expectedReasonCode,
        string $expectedReasonMessage,
        string $expectedFailingInvocationId,
        bool $expectToolVersion,
    ): void {
        $this->withProject(function (string $root) use (
            $stage,
            $mode,
            $timeoutMilliseconds,
            $expectedInvocationCount,
            $expectedStatus,
            $expectedReasonCode,
            $expectedReasonMessage,
            $expectedFailingInvocationId,
            $expectToolVersion,
        ): void {
            self::writeStubResponse($root, $stage, ['mode' => $mode]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), $timeoutMilliseconds);

            self::assertSame(ExitCode::ADAPTER_FAILED, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     adapter: array{tool_version: string|null},
             *     reason: array{code: string, message: string},
             *     invocations: list<array{id: string, status: string, exit_code: int|null, signal: int|null}>,
             *     summary: array{finding_count: int},
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('failed', $report['status']);
            self::assertSame($expectedReasonCode, $report['reason']['code']);
            self::assertSame($expectedReasonMessage, $report['reason']['message']);
            self::assertCount($expectedInvocationCount, $report['invocations']);
            self::assertSame(0, $report['summary']['finding_count']);

            $failing = self::findInvocation($report['invocations'], $expectedFailingInvocationId);
            self::assertSame($expectedStatus, $failing['status']);
            self::assertNull($failing['exit_code']);
            if ($expectedStatus === 'signaled') {
                self::assertNotNull($failing['signal']);
            } else {
                self::assertNull($failing['signal']);
            }

            self::assertSame($expectToolVersion, $report['adapter']['tool_version'] !== null);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow8VersionProbeNonZeroExitIsProcessExitFailed(): void
    {
        $this->withProject(function (string $root): void {
            self::writeStubResponse($root, 'version', ['exit' => 3]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::ADAPTER_FAILED, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     adapter: array{tool_version: string|null},
             *     reason: array{code: string, message: string},
             *     invocations: list<array{id: string, status: string, exit_code: int|null}>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('failed', $report['status']);
            self::assertSame(VerificationReason::PROCESS_EXIT_FAILED, $report['reason']['code']);
            self::assertSame(
                'The PHP_CodeSniffer version probe exited with a non-zero status.',
                $report['reason']['message'],
            );
            self::assertCount(1, $report['invocations']);
            self::assertSame('exited', $report['invocations'][0]['status']);
            self::assertSame(3, $report['invocations'][0]['exit_code']);
            self::assertNull($report['adapter']['tool_version']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow9VersionProbeThatDoesNotReportAVersionIsCapabilityUnavailable(): void
    {
        $this->withProject(function (string $root): void {
            self::writeStubResponse($root, 'version', ['txt' => "not phpcs\n"]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     adapter: array{tool_version: string|null},
             *     reason: array{code: string, message: string},
             *     invocations: list<array{id: string, status: string, exit_code: int|null}>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('unavailable', $report['status']);
            self::assertSame(VerificationReason::CAPABILITY_UNAVAILABLE, $report['reason']['code']);
            self::assertSame(
                'The selected executable did not report a PHP_CodeSniffer version.',
                $report['reason']['message'],
            );
            self::assertCount(1, $report['invocations']);
            self::assertSame('exited', $report['invocations'][0]['status']);
            self::assertSame(0, $report['invocations'][0]['exit_code']);
            self::assertNull($report['adapter']['tool_version']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow15StandardsProbeNonZeroExitIsProcessExitFailed(): void
    {
        $this->withProject(function (string $root): void {
            self::writeStubResponse($root, 'standards', ['exit' => 1]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::ADAPTER_FAILED, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     reason: array{code: string, message: string},
             *     invocations: list<array{id: string, status: string, exit_code: int|null, signal: int|null}>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('failed', $report['status']);
            self::assertSame(VerificationReason::PROCESS_EXIT_FAILED, $report['reason']['code']);
            self::assertSame(
                'The PHP_CodeSniffer standards probe exited with a non-zero status.',
                $report['reason']['message'],
            );
            self::assertCount(2, $report['invocations']);
            $standardsInvocation = self::findInvocation($report['invocations'], 'verification-2');
            self::assertSame('exited', $standardsInvocation['status']);
            self::assertSame(1, $standardsInvocation['exit_code']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    #[DataProvider('standardsNotRegisteredCases')]
    public function testRow16UnregisteredOrConfusableStandardIsCapabilityUnavailable(string $standardsText): void
    {
        $this->withProject(function (string $root) use ($standardsText): void {
            self::writeStubResponse($root, 'standards', ['txt' => $standardsText]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     adapter: array{tool_version: string|null},
             *     reason: array{code: string, message: string},
             *     invocations: list<array{id: string, status: string}>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('unavailable', $report['status']);
            self::assertSame(VerificationReason::CAPABILITY_UNAVAILABLE, $report['reason']['code']);
            self::assertSame(
                'The selected PHP_CodeSniffer installation does not register the PHPCompatibility standard.',
                $report['reason']['message'],
            );
            self::assertCount(2, $report['invocations']);
            foreach ($report['invocations'] as $invocation) {
                self::assertSame('exited', $invocation['status']);
            }
            self::assertNotNull($report['adapter']['tool_version']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow22AnalysisExitOutsideTheKnownRangeIsProcessExitFailed(): void
    {
        $this->withProject(function (string $root): void {
            self::writeStubResponse($root, 'analysis', [
                'txt' => "ERROR: an unrecoverable processing error occurred while scanning files.\n",
                'exit' => 3,
            ]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::ADAPTER_FAILED, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     reason: array{code: string, message: string},
             *     invocations: list<array{id: string, status: string, exit_code: int|null, signal: int|null}>,
             *     summary: array{finding_count: int},
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('failed', $report['status']);
            self::assertSame(VerificationReason::PROCESS_EXIT_FAILED, $report['reason']['code']);
            self::assertSame(
                'PHP_CodeSniffer exited with a status that does not indicate a completed analysis.',
                $report['reason']['message'],
            );
            self::assertCount(3, $report['invocations']);
            $analysisInvocation = self::findInvocation($report['invocations'], 'verification-3');
            self::assertSame('exited', $analysisInvocation['status']);
            self::assertSame(3, $analysisInvocation['exit_code']);
            self::assertSame(0, $report['summary']['finding_count']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    #[DataProvider('analysisStructuralFailureCases')]
    public function testAnalysisOutputStructuralFailuresAreReportedAsOutputInvalid(
        ?string $analysisText,
        ?int $analysisExit,
        string $expectedMessage,
        ?string $forbiddenSubstring,
    ): void {
        $this->withProject(function (string $root) use (
            $analysisText,
            $analysisExit,
            $expectedMessage,
            $forbiddenSubstring,
        ): void {
            /** @var array{txt?: string, exit?: int} $response */
            $response = [];
            if ($analysisText !== null) {
                $response['txt'] = $analysisText;
            }
            if ($analysisExit !== null) {
                $response['exit'] = $analysisExit;
            }
            if ($response !== []) {
                self::writeStubResponse($root, 'analysis', $response);
            }
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::ADAPTER_FAILED, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     reason: array{code: string, message: string},
             *     invocations: list<array{id: string, status: string}>,
             *     summary: array{finding_count: int},
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('failed', $report['status']);
            self::assertSame(VerificationReason::OUTPUT_INVALID, $report['reason']['code']);
            self::assertSame($expectedMessage, $report['reason']['message']);
            self::assertCount(3, $report['invocations']);
            self::assertSame(0, $report['summary']['finding_count']);
            if ($forbiddenSubstring !== null) {
                self::assertStringNotContainsString($forbiddenSubstring, $tester->getDisplay());
            }

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow29DefaultAnalysisResponseCompletesWithNoFindings(): void
    {
        $this->withProject(function (string $root): void {
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::SUCCESS, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     reason: mixed,
             *     adapter: array{tool_version: string|null},
             *     invocations: list<array{id: string, status: string, exit_code: int|null}>,
             *     summary: array{finding_count: int},
             *     findings: list<mixed>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('success', $report['status']);
            self::assertNull($report['reason']);
            self::assertCount(3, $report['invocations']);
            foreach ($report['invocations'] as $invocation) {
                self::assertSame('exited', $invocation['status']);
                self::assertSame(0, $invocation['exit_code']);
            }
            self::assertSame(0, $report['summary']['finding_count']);
            self::assertSame([], $report['findings']);

            $toolVersion = $report['adapter']['tool_version'];
            self::assertIsString($toolVersion);
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $toolVersion);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow30aMappedAndUnmappedFindingsAreBothPublished(): void
    {
        $this->withProject(function (string $root): void {
            $payload = json_encode([
                'totals' => ['errors' => 1, 'warnings' => 1, 'fixable' => 0],
                'files' => [
                    'src/Foo.php' => [
                        'errors' => 1,
                        'warnings' => 1,
                        'messages' => [
                            [
                                'message' => 'Typed constants are not supported in PHP 8.2 or earlier. '
                                    . 'Found: string',
                                'source' => 'PHPCompatibility.Classes.NewTypedConstants.Found',
                                'severity' => 5,
                                'type' => 'ERROR',
                                'line' => 3,
                                'column' => 5,
                            ],
                            [
                                'message' => 'This sniff id is not in the committed map.',
                                'source' => 'PHPCompatibility.Some.Unmapped.Thing',
                                'severity' => 5,
                                'type' => 'WARNING',
                                'line' => 4,
                                'column' => 5,
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            self::writeStubResponse($root, 'analysis', ['txt' => $payload, 'exit' => 1]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     reason: mixed,
             *     summary: array{
             *         finding_count: int,
             *         mapped_finding_count: int,
             *         unmapped_finding_count: int,
             *         mapping_count: int,
             *         mapped_rule_count: int,
             *     },
             *     rule_contexts: list<array{id: string}>,
             *     findings: list<array{external_rule_id: string, mapping_status: string, mapped_rule_ids: list<string>}>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('findings', $report['status']);
            self::assertNull($report['reason']);
            self::assertSame(2, $report['summary']['finding_count']);
            self::assertSame(1, $report['summary']['mapped_finding_count']);
            self::assertSame(1, $report['summary']['unmapped_finding_count']);
            self::assertSame(1, $report['summary']['mapping_count']);
            self::assertSame(1, $report['summary']['mapped_rule_count']);

            $ruleContextIds = [];
            foreach ($report['rule_contexts'] as $context) {
                $ruleContextIds[] = $context['id'];
            }
            self::assertSame(['language.typed_class_constants'], $ruleContextIds);

            $bySniffId = [];
            foreach ($report['findings'] as $finding) {
                $bySniffId[$finding['external_rule_id']] = $finding;
            }
            self::assertSame('mapped', $bySniffId['PHPCompatibility.Classes.NewTypedConstants.Found']['mapping_status']);
            self::assertSame(
                ['language.typed_class_constants'],
                $bySniffId['PHPCompatibility.Classes.NewTypedConstants.Found']['mapped_rule_ids'],
            );
            self::assertSame('unmapped', $bySniffId['PHPCompatibility.Some.Unmapped.Thing']['mapping_status']);
            self::assertSame([], $bySniffId['PHPCompatibility.Some.Unmapped.Thing']['mapped_rule_ids']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow30bExitZeroWithFindingsIsStillReportedAsFindings(): void
    {
        $this->withProject(function (string $root): void {
            $payload = json_encode([
                'totals' => ['errors' => 0, 'warnings' => 1, 'fixable' => 0],
                'files' => [
                    'src/Foo.php' => [
                        'errors' => 0,
                        'warnings' => 1,
                        'messages' => [
                            [
                                'message' => 'Function curl_close() is deprecated since PHP 8.5',
                                'source' => 'PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated',
                                'severity' => 5,
                                'type' => 'WARNING',
                                'line' => 2,
                                'column' => 3,
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            // analysis.exit is deliberately left at its default of 0: phpcs's own ignore_warnings_on_exit
            // install config can make exit 0 even though a finding was published, so the adapter's own
            // exit code must be derived from the outcome, never mirrored from the analyzer's raw exit.
            self::writeStubResponse($root, 'analysis', ['txt' => $payload]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     invocations: list<array{id: string, status: string, exit_code: int|null, signal: int|null}>,
             *     summary: array{finding_count: int},
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('findings', $report['status']);
            self::assertSame(1, $report['summary']['finding_count']);
            $analysisInvocation = self::findInvocation($report['invocations'], 'verification-3');
            self::assertSame('exited', $analysisInvocation['status']);
            self::assertSame(0, $analysisInvocation['exit_code']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow30cByteIdenticalMessagesAreDeduplicated(): void
    {
        $this->withProject(function (string $root): void {
            $message = [
                'message' => 'Using ${a} (variable variables) in strings is deprecated since PHP 8.2, use '
                    . '{${expr}} instead.',
                'source' => 'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax',
                'severity' => 5,
                'type' => 'WARNING',
                'line' => 4,
                'column' => 6,
            ];
            $payload = json_encode([
                'totals' => ['errors' => 0, 'warnings' => 2, 'fixable' => 0],
                'files' => [
                    'src/Foo.php' => [
                        'errors' => 0,
                        'warnings' => 2,
                        'messages' => [$message, $message],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            self::writeStubResponse($root, 'analysis', ['txt' => $payload]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     summary: array{finding_count: int},
             *     findings: list<array{external_rule_id: string}>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('findings', $report['status']);
            self::assertSame(1, $report['summary']['finding_count']);
            self::assertCount(1, $report['findings']);
            self::assertSame(
                'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax',
                $report['findings'][0]['external_rule_id'],
            );

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow30dInternalExceptionIsPreservedAsAnUnmappedFinding(): void
    {
        $this->withProject(function (string $root): void {
            $exceptionMessage = 'An error occurred during processing; checking has been aborted. The error '
                . 'message was: Something went wrong while tokenizing this file.';
            $payload = json_encode([
                'totals' => ['errors' => 1, 'warnings' => 0, 'fixable' => 0],
                'files' => [
                    'src/Foo.php' => [
                        'errors' => 1,
                        'warnings' => 0,
                        'messages' => [
                            [
                                'message' => $exceptionMessage,
                                'source' => 'Internal.Exception',
                                'severity' => 5,
                                'type' => 'ERROR',
                                'line' => 1,
                                'column' => 1,
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            self::writeStubResponse($root, 'analysis', ['txt' => $payload]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     findings: list<array{external_rule_id: string, message: string, mapping_status: string, mapped_rule_ids: list<string>}>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('findings', $report['status']);
            self::assertCount(1, $report['findings']);
            self::assertSame('Internal.Exception', $report['findings'][0]['external_rule_id']);
            self::assertSame($exceptionMessage, $report['findings'][0]['message']);
            self::assertSame('unmapped', $report['findings'][0]['mapping_status']);
            self::assertSame([], $report['findings'][0]['mapped_rule_ids']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    public function testRow30eUnusableAnnotativeFieldsDegradeToNull(): void
    {
        $this->withProject(function (string $root): void {
            $payload = json_encode([
                'totals' => ['errors' => 1, 'warnings' => 0, 'fixable' => 0],
                'files' => [
                    'src/Foo.php' => [
                        'errors' => 1,
                        'warnings' => 0,
                        'messages' => [
                            [
                                'message' => 'A message with unusable annotative fields.',
                                'source' => 'PHPCompatibility.Some.Unmapped.Thing',
                                'severity' => '5',
                                'type' => '',
                                'line' => 0,
                                'column' => 0,
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            self::writeStubResponse($root, 'analysis', ['txt' => $payload]);
            $before = FixtureTreeSnapshot::capture($root);

            [$exitCode, $tester] = $this->runVerify($root, self::stub(), null);

            self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
            /**
             * @var array{
             *     status: string,
             *     findings: list<array{type: string|null, severity: string|null, line: int|null, column: int|null, message: string}>,
             * } $report
             */
            $report = $this->decodedReport($tester, $root);

            self::assertSame('findings', $report['status']);
            self::assertCount(1, $report['findings']);
            self::assertNull($report['findings'][0]['type']);
            self::assertNull($report['findings'][0]['severity']);
            self::assertNull($report['findings'][0]['line']);
            self::assertNull($report['findings'][0]['column']);
            self::assertSame('A message with unusable annotative fields.', $report['findings'][0]['message']);

            self::assertSame($before, FixtureTreeSnapshot::capture($root));
        });
    }

    /** @param \Closure(string): void $body */
    private function withProject(\Closure $body): void
    {
        $root = sys_get_temp_dir() . '/php-modern-guidelines-stub-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($root, 0700));
        self::assertNotFalse(file_put_contents(
            $root . '/composer.json',
            '{"name":"fixture/stub","require":{"php":">=8.2 <8.6"}}',
        ));

        try {
            $body($root);
        } finally {
            self::removeTree($root);
        }
    }

    /** @param array{mode?: string, txt?: string, exit?: int} $response */
    private static function writeStubResponse(string $root, string $stage, array $response): void
    {
        $directory = $root . '/stub-response';
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0700));
        }

        if (array_key_exists('mode', $response)) {
            self::assertNotFalse(file_put_contents($directory . '/' . $stage . '.mode', $response['mode']));
        }
        if (array_key_exists('txt', $response)) {
            self::assertNotFalse(file_put_contents($directory . '/' . $stage . '.txt', $response['txt']));
        }
        if (array_key_exists('exit', $response)) {
            self::assertNotFalse(file_put_contents($directory . '/' . $stage . '.exit', (string) $response['exit']));
        }
    }

    private static function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($root);
    }

    /** @return array{0: int, 1: ApplicationTester} */
    private function runVerify(string $root, string $executable, ?int $timeoutMilliseconds): array
    {
        $adapter = $timeoutMilliseconds === null
            ? new PhpCompatibilityAdapter()
            : new PhpCompatibilityAdapter($timeoutMilliseconds);

        $application = new Application('php-modern-guidelines-test');
        $application->add(new VerifyCommand(new VerificationAdapterRegistry([$adapter])));
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $tester = new ApplicationTester($application);

        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'phpcompatibility',
                '--executable' => $executable,
                '--project-root' => $root,
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        return [$exitCode, $tester];
    }

    /** @return array<string, mixed> */
    private function decodedReport(ApplicationTester $tester, string $root): array
    {
        self::assertSame('', $tester->getErrorOutput());
        self::assertStringEndsWith("\n", $tester->getDisplay());
        self::assertStringNotContainsString($root, $tester->getDisplay());

        $tree = json_decode($tester->getDisplay(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $tree);
        self::assertSame([], (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($tree));

        /** @var array<string, mixed> $report */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        return $report;
    }

    /**
     * @param  list<array{id: string, status: string, exit_code: int|null, signal: int|null}> $invocations
     * @return array{id: string, status: string, exit_code: int|null, signal: int|null}
     */
    private static function findInvocation(array $invocations, string $id): array
    {
        foreach ($invocations as $invocation) {
            if ($invocation['id'] === $id) {
                return $invocation;
            }
        }

        self::fail(sprintf('No invocation "%s" was recorded.', $id));
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
        $path = realpath(__DIR__ . '/../fixtures/verification/stub/phpcs-stub');
        self::assertIsString($path);

        return $path;
    }

    private static function notAProgram(): string
    {
        $path = realpath(__DIR__ . '/../fixtures/verification/stub/not-a-program');
        self::assertIsString($path);

        return $path;
    }
}

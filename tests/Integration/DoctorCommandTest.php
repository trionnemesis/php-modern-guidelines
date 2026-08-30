<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Diagnostics\CheckStatus;
use ModernPhpGuidelines\Diagnostics\DiagnosticCheck;
use ModernPhpGuidelines\Diagnostics\DoctorRunner;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\ResolutionMode;
use ModernPhpGuidelines\Support\PackagePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * `doctor` golden human + JSON output, exit codes, and stdout/stderr behaviour (D19).
 * WORK-ORDER.md §5.7, rows G-T15 through G-T27.
 */
final class DoctorCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/projects';
    private const RULES_FIXTURES = __DIR__ . '/../fixtures/rules';
    private const CLI_GOLDEN = __DIR__ . '/../fixtures/cli';

    // --- G-T15 -----------------------------------------------------------------------------------

    public function testCaretEightTwoHumanOutputMatchesTheGolden(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'doctor', '--project-root' => $fixtureRealPath],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame($this->goldenContents('doctor-caret-8-2.txt', $fixtureRealPath), $tester->getDisplay());
    }

    // --- G-T16 -----------------------------------------------------------------------------------

    public function testCaretEightTwoJsonOutputMatchesTheGolden(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'doctor', '--project-root' => $fixtureRealPath, '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame($this->goldenContents('doctor-caret-8-2.json', $fixtureRealPath), $tester->getDisplay());
    }

    // --- G-T17 -----------------------------------------------------------------------------------

    public function testNoComposerJsonHumanOutputMatchesTheGolden(): void
    {
        $fixtureRealPath = $this->realFixture('no-composer-json');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'doctor', '--project-root' => $fixtureRealPath],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame($this->goldenContents('doctor-no-composer-json.txt', $fixtureRealPath), $tester->getDisplay());
    }

    // --- G-T18 -----------------------------------------------------------------------------------

    public function testMalformedComposerJsonHumanOutputMatchesTheGoldenWithNonEmptyStdoutAndEmptyStderr(): void
    {
        $fixtureRealPath = $this->realFixture('malformed-composer-json');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'doctor', '--project-root' => $fixtureRealPath],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertNotSame('', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame($this->goldenContents('doctor-malformed-composer-json.txt', $fixtureRealPath), $tester->getDisplay());
    }

    // --- G-T19 -----------------------------------------------------------------------------------

    public function testMalformedComposerJsonJsonOutputMatchesTheGoldenAndIsCompleteJson(): void
    {
        $fixtureRealPath = $this->realFixture('malformed-composer-json');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'doctor', '--project-root' => $fixtureRealPath, '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getErrorOutput());

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame('1.0.0', $decoded['output_version']);
        self::assertSame(2, $decoded['exit_code']);

        self::assertSame($this->goldenContents('doctor-malformed-composer-json.json', $fixtureRealPath), $tester->getDisplay());
    }

    // --- G-T20 -----------------------------------------------------------------------------------

    public function testConflictEmptiesRangeExitsFourWithNonEmptyStdout(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'doctor', '--project-root' => $this->realFixture('conflict-empties-range')],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::UNRESOLVABLE_POLICY, $exitCode);
        self::assertNotSame('', $tester->getDisplay());
        self::assertStringContainsString('[fail]    policy.resolution', $tester->getDisplay());
    }

    // --- G-T21 -----------------------------------------------------------------------------------

    public function testUnknownModeExitsTwoWithByteEmptyStdout(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'doctor', '--project-root' => $this->realFixture('caret-8-2'), '--mode' => 'nope'],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringStartsWith('Error: Unknown mode "nope".', $tester->getErrorOutput());
    }

    // --- G-T22 -----------------------------------------------------------------------------------

    public function testPhpWithRuntimeObservedContradictionExitsTwoWithByteEmptyStdout(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            [
                'command' => 'doctor',
                '--project-root' => $this->realFixture('caret-8-2'),
                '--mode' => 'runtime-observed',
                '--php' => '8.4',
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getDisplay());
    }

    // --- G-T23 -----------------------------------------------------------------------------------

    public function testRuntimeObservedModeIsStructurallyValidWithNoGolden(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'doctor', '--project-root' => $this->realFixture('caret-8-2'), '--mode' => 'runtime-observed', '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['checks']);
        self::assertCount(9, $decoded['checks']);

        $policy = null;
        foreach ($decoded['checks'] as $check) {
            self::assertIsArray($check);
            if ($check['id'] === 'policy.resolution') {
                $policy = $check;
            }
        }

        self::assertIsArray($policy);
        self::assertIsArray($policy['details']);
        self::assertNotNull($policy['details']['observed_runtime']);
        self::assertNotSame('-', $policy['details']['observed_runtime']);
    }

    // --- G-T24 -----------------------------------------------------------------------------------

    public function testMissingRulesDirExitsFiveWithThePinnedSummaryAndNoGolden(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            [
                'command' => 'doctor',
                '--project-root' => $this->realFixture('caret-8-2'),
                '--rules-dir' => self::RULES_FIXTURES . '/this-directory-does-not-exist',
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::RULE_DATA_INVALID, $exitCode);
        self::assertStringContainsString('[fail]    rules.directory', $tester->getDisplay());
        self::assertStringContainsString('custom rules directory is missing or unreadable', $tester->getDisplay());
    }

    // --- G-T25 -----------------------------------------------------------------------------------
    // Covered by the unmodified M1 suites (ResolveCommandTest, ListCommandTest, ExplainCommandTest):
    // doctor introduces no change to resolve / list-rules / explain output.

    // --- G-T26 -----------------------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function doctorGoldenFiles(): iterable
    {
        foreach (glob(self::CLI_GOLDEN . '/doctor-*') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[DataProvider('doctorGoldenFiles')]
    public function testEveryDoctorGoldenStoresTheVersionPlaceholderAndNoLiteralVersion(string $path): void
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringContainsString('@VERSION@', $contents, sprintf('%s does not contain @VERSION@.', $path));
        self::assertStringNotContainsString('0.1.0', $contents, sprintf('%s contains a literal version string.', $path));
        self::assertStringNotContainsString('0.2.0', $contents, sprintf('%s contains a literal version string.', $path));
        self::assertStringNotContainsString('/path/to/app', $contents, sprintf('%s contains a skill placeholder.', $path));
        self::assertStringNotContainsString('<app>', $contents, sprintf('%s contains a skill placeholder.', $path));
        self::assertStringNotContainsString('<version>', $contents, sprintf('%s contains a skill placeholder.', $path));
    }

    // --- G-T27 -----------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string|null, string|null, string|null}> [fixture, rawProjectRoot, rawRulesDirOption]
     */
    public static function noPathLeakScenarios(): iterable
    {
        yield 'caret-8-2' => ['caret-8-2', null, null];
        yield 'no-composer-json' => ['no-composer-json', null, null];
        yield 'malformed-composer-json' => ['malformed-composer-json', null, null];
        yield 'malformed-composer-lock' => ['malformed-composer-lock', null, null];
        yield 'non-string-constraint' => ['non-string-constraint', null, null];
        yield 'conflict-empties-range' => ['conflict-empties-range', null, null];
        yield 'missing project root' => [null, self::FIXTURES . '/this-directory-does-not-exist', null];
        yield 'missing --rules-dir' => ['caret-8-2', null, self::RULES_FIXTURES . '/this-directory-does-not-exist'];
        yield 'filename-mismatch --rules-dir' => ['caret-8-2', null, self::RULES_FIXTURES . '/invalid/filename-mismatch'];
    }

    /**
     * G-T27's three-part algorithm (WORK-ORDER.md §5.7): no summary and no detail value carries an
     * absolute path other than the project root, across every case in the test matrix.
     *
     * @param string|null $fixture           a fixture directory name under tests/fixtures/projects, or
     *                                       null when $rawProjectRoot is given directly
     * @param string|null $rawProjectRoot    a literal (non-fixture) --project-root value; mutually
     *                                       exclusive with $fixture
     * @param string|null $rawRulesDirOption a literal --rules-dir value, or null for the bundled dir
     */
    #[DataProvider('noPathLeakScenarios')]
    public function testNoCheckLeaksAnAbsolutePathOtherThanTheProjectRoot(
        ?string $fixture,
        ?string $rawProjectRoot,
        ?string $rawRulesDirOption,
    ): void {
        $projectRootGiven = $fixture !== null ? $this->realFixture($fixture) : $rawProjectRoot;
        self::assertIsString($projectRootGiven);

        $rulesDirGiven = $rawRulesDirOption !== null
            ? (realpath($rawRulesDirOption) ?: $rawRulesDirOption)
            : null;

        $report = (new DoctorRunner())->run(new PolicyRequest($projectRootGiven, ResolutionMode::RangeSafe), $rulesDirGiven);

        $projectRootRealpath = is_dir($projectRootGiven) ? (realpath($projectRootGiven) ?: $projectRootGiven) : $projectRootGiven;

        $forbiddenLiterals = array_values(array_unique(array_filter([
            dirname(PackagePaths::rulesDirectory(), 2),
            realpath(dirname(__DIR__, 2)) ?: null,
            realpath(sys_get_temp_dir()) ?: null,
            $projectRootGiven,
            $rulesDirGiven,
        ], static fn(?string $value): bool => $value !== null && $value !== '')));

        foreach ($report->checks as $check) {
            $this->assertCheckLeaksNoAbsolutePath($check, $projectRootRealpath, $projectRootGiven, $forbiddenLiterals);
        }
    }

    /** @param list<string> $forbiddenLiterals */
    private function assertCheckLeaksNoAbsolutePath(
        DiagnosticCheck $check,
        string $projectRootRealpath,
        string $projectRootGiven,
        array $forbiddenLiterals,
    ): void {
        if ($check->id === 'project.root') {
            if ($check->status === CheckStatus::Ok) {
                self::assertSame($projectRootRealpath, $check->summary);
                self::assertSame($projectRootRealpath, $check->details['path']);
            } else {
                self::assertSame('not an existing directory: ' . $projectRootGiven, $check->summary);
            }

            return;
        }

        self::assertStringNotContainsString(
            '/',
            $check->summary,
            sprintf('%s summary leaks a path: "%s".', $check->id, $check->summary),
        );

        foreach ($check->details as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($key === 'error') {
                foreach ($forbiddenLiterals as $literal) {
                    self::assertStringNotContainsString(
                        $literal,
                        $value,
                        sprintf('%s.error leaks "%s": "%s".', $check->id, $literal, $value),
                    );
                }

                continue;
            }

            self::assertStringNotContainsString(
                '/',
                $value,
                sprintf('%s.%s leaks a path: "%s".', $check->id, $key, $value),
            );
        }
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function tester(): ApplicationTester
    {
        $application = ApplicationFactory::create();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return new ApplicationTester($application);
    }

    private function realFixture(string $fixture): string
    {
        $path = realpath(self::FIXTURES . '/' . $fixture);
        self::assertIsString($path, sprintf('Fixture directory "%s" does not exist.', $fixture));

        return $path;
    }

    private function goldenContents(string $filename, string $fixtureRealPath): string
    {
        $path = self::CLI_GOLDEN . '/' . $filename;
        $contents = file_get_contents($path);
        self::assertIsString($contents, sprintf('Could not read golden file %s.', $path));

        return str_replace(
            ['@PROJECT_ROOT@', '@VERSION@'],
            [$fixtureRealPath, ApplicationFactory::VERSION],
            $contents,
        );
    }
}

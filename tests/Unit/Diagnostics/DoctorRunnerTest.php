<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Diagnostics;

use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Diagnostics\CheckStatus;
use ModernPhpGuidelines\Diagnostics\DiagnosticCheck;
use ModernPhpGuidelines\Diagnostics\DiagnosticReport;
use ModernPhpGuidelines\Diagnostics\DoctorRunner;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\ResolutionMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives DoctorRunner directly against fixture project directories, asserting statuses, detail keys
 * and the selected exit code. No output rendering (that is DoctorCommandTest's job). WORK-ORDER.md
 * §5.7, rows G-T1 through G-T14 plus the self-enforcing G-T28 Phar scan.
 */
final class DoctorRunnerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/projects';
    private const RULES_FIXTURES = __DIR__ . '/../../fixtures/rules';

    private DoctorRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new DoctorRunner();
    }

    // --- G-T1 ----------------------------------------------------------------------------------

    public function testCaretEightTwoProducesNineChecksInFixedOrderWithWarnPolicyResolution(): void
    {
        $report = $this->runFixture('caret-8-2');

        self::assertSame(DoctorRunner::CHECK_IDS, $this->ids($report));
        self::assertSame(CheckStatus::Warn, $this->check($report, 'policy.resolution')->status);
        self::assertSame(CheckStatus::Warn, $report->status());
        self::assertSame(ExitCode::SUCCESS, $report->exitCode());
    }

    // --- G-T2 ----------------------------------------------------------------------------------

    public function testNoComposerJsonWarnsAndReadsAllFourDeclaredValuesAsNull(): void
    {
        $report = $this->runFixture('no-composer-json');

        self::assertSame(CheckStatus::Warn, $this->check($report, 'project.composer_json')->status);

        $declarations = $this->check($report, 'project.php_declarations');
        self::assertSame(CheckStatus::Ok, $declarations->status);
        self::assertNull($declarations->details['require_php']);
        self::assertNull($declarations->details['conflict_php']);
        self::assertNull($declarations->details['config_platform_php']);
        self::assertNull($declarations->details['platform_overrides_php']);

        self::assertSame(ExitCode::SUCCESS, $report->exitCode());
    }

    // --- G-T3 ----------------------------------------------------------------------------------

    public function testMalformedComposerJsonFailsAndSkipsDeclarationsAndPolicy(): void
    {
        $report = $this->runFixture('malformed-composer-json');

        $composerJson = $this->check($report, 'project.composer_json');
        self::assertSame(CheckStatus::Fail, $composerJson->status);
        self::assertSame('invalid', $composerJson->details['json']);

        self::assertSame(CheckStatus::Skipped, $this->check($report, 'project.php_declarations')->status);
        self::assertTrue($this->allDetailValuesNull($this->check($report, 'project.php_declarations')));
        self::assertSame(CheckStatus::Skipped, $this->check($report, 'policy.resolution')->status);
        self::assertTrue($this->allDetailValuesNull($this->check($report, 'policy.resolution')));

        self::assertSame(CheckStatus::Ok, $this->check($report, 'schemas.available')->status);
        self::assertSame(CheckStatus::Ok, $this->check($report, 'rules.directory')->status);
        self::assertSame(CheckStatus::Ok, $this->check($report, 'rules.load')->status);

        self::assertSame(ExitCode::INVALID_INPUT, $report->exitCode());
    }

    // --- G-T4 ----------------------------------------------------------------------------------

    public function testMalformedComposerLockFailsAndSkipsDeclarationsAndPolicy(): void
    {
        $report = $this->runFixture('malformed-composer-lock');

        self::assertSame(CheckStatus::Fail, $this->check($report, 'project.composer_lock')->status);
        self::assertSame(CheckStatus::Skipped, $this->check($report, 'project.php_declarations')->status);
        self::assertSame(CheckStatus::Skipped, $this->check($report, 'policy.resolution')->status);
        self::assertSame(ExitCode::INVALID_INPUT, $report->exitCode());
    }

    // --- G-T5 ----------------------------------------------------------------------------------

    public function testNonStringConstraintFailsDeclarationsAndSkipsPolicy(): void
    {
        $report = $this->runFixture('non-string-constraint');

        self::assertSame(CheckStatus::Ok, $this->check($report, 'project.composer_json')->status);
        self::assertSame(CheckStatus::Ok, $this->check($report, 'project.composer_lock')->status);

        $declarations = $this->check($report, 'project.php_declarations');
        self::assertSame(CheckStatus::Fail, $declarations->status);
        self::assertSame('composer.json require.php must be a string, got int.', $declarations->details['error']);

        self::assertSame(CheckStatus::Skipped, $this->check($report, 'policy.resolution')->status);
        self::assertSame(ExitCode::INVALID_INPUT, $report->exitCode());
    }

    // --- G-T6 ----------------------------------------------------------------------------------

    public function testConflictEmptiesRangeFailsPolicyResolutionWithExitFour(): void
    {
        $report = $this->runFixture('conflict-empties-range');

        self::assertSame(CheckStatus::Ok, $this->check($report, 'project.php_declarations')->status);

        $policy = $this->check($report, 'policy.resolution');
        self::assertSame(CheckStatus::Fail, $policy->status);
        self::assertSame(ExitCode::UNRESOLVABLE_POLICY, $policy->exitCode);

        self::assertSame(ExitCode::UNRESOLVABLE_POLICY, $report->exitCode());
    }

    // --- G-T7 ----------------------------------------------------------------------------------

    public function testNonExistentProjectRootFailsAndSkipsThreeFourFiveSix(): void
    {
        $missing = self::FIXTURES . '/this-directory-does-not-exist';
        self::assertDirectoryDoesNotExist($missing);

        $request = new PolicyRequest($missing, ResolutionMode::RangeSafe);
        $report = $this->runner->run($request, null);

        self::assertSame(CheckStatus::Fail, $this->check($report, 'project.root')->status);
        self::assertSame(CheckStatus::Skipped, $this->check($report, 'project.composer_json')->status);
        self::assertSame(CheckStatus::Skipped, $this->check($report, 'project.composer_lock')->status);
        self::assertSame(CheckStatus::Skipped, $this->check($report, 'project.php_declarations')->status);
        self::assertSame(CheckStatus::Skipped, $this->check($report, 'policy.resolution')->status);
        self::assertSame(CheckStatus::Ok, $this->check($report, 'schemas.available')->status);
        self::assertSame(CheckStatus::Ok, $this->check($report, 'rules.directory')->status);
        self::assertSame(CheckStatus::Ok, $this->check($report, 'rules.load')->status);

        self::assertSame(ExitCode::INVALID_INPUT, $report->exitCode());
    }

    // --- G-T8 ----------------------------------------------------------------------------------

    public function testMissingRulesDirFailsRulesDirectoryAndSkipsRulesLoad(): void
    {
        $report = $this->runFixture('caret-8-2', self::RULES_FIXTURES . '/this-directory-does-not-exist');

        self::assertSame(CheckStatus::Fail, $this->check($report, 'rules.directory')->status);
        self::assertSame(CheckStatus::Skipped, $this->check($report, 'rules.load')->status);
        self::assertSame(ExitCode::RULE_DATA_INVALID, $report->exitCode());
    }

    // --- G-T9 ----------------------------------------------------------------------------------

    public function testFilenameMismatchRuleFailsRulesLoadWithThePinnedMessage(): void
    {
        $rulesDir = realpath(self::RULES_FIXTURES . '/invalid/filename-mismatch');
        self::assertIsString($rulesDir);

        $report = $this->runFixture('caret-8-2', $rulesDir);

        $rulesLoad = $this->check($report, 'rules.load');
        self::assertSame(CheckStatus::Fail, $rulesLoad->status);
        self::assertSame(
            'Rule file "a.json" must be named "language.b.json" to match its id.',
            $rulesLoad->details['error'],
        );
        self::assertSame(ExitCode::RULE_DATA_INVALID, $report->exitCode());
    }

    // --- G-T10 ---------------------------------------------------------------------------------

    public function testEmptyRulesDirectoryWarnsAndRulesLoadStillSucceedsWithZero(): void
    {
        $emptyDir = sys_get_temp_dir() . '/doctor-runner-test-empty-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($emptyDir, 0755, true));

        try {
            $report = $this->runFixture('caret-8-2', $emptyDir);

            $rulesDirectory = $this->check($report, 'rules.directory');
            self::assertSame(CheckStatus::Warn, $rulesDirectory->status);
            self::assertSame('0', $rulesDirectory->details['file_count']);

            $rulesLoad = $this->check($report, 'rules.load');
            self::assertSame(CheckStatus::Ok, $rulesLoad->status);
            self::assertSame('0', $rulesLoad->details['loaded']);

            self::assertSame(ExitCode::SUCCESS, $report->exitCode());
        } finally {
            rmdir($emptyDir);
        }
    }

    // --- G-T11 -----------------------------------------------------------------------------------

    /** @return iterable<string, array{string, list<string>}> */
    public static function detailKeySets(): iterable
    {
        yield 'cli.build' => ['cli.build', ['version', 'distribution']];
        yield 'project.root' => ['project.root', ['path', 'is_directory']];
        yield 'project.composer_json' => ['project.composer_json', ['path', 'present', 'readable', 'json']];
        yield 'project.composer_lock' => ['project.composer_lock', ['path', 'present', 'readable', 'json']];
        yield 'project.php_declarations' => ['project.php_declarations', [
            'require_php', 'conflict_php', 'config_platform_php', 'platform_overrides_php', 'input_warnings', 'error',
        ]];
        yield 'policy.resolution' => ['policy.resolution', [
            'mode', 'allowed_minors', 'feature_ceiling', 'lifecycle_ceiling', 'platform_override',
            'observed_runtime', 'coverage', 'confidence', 'warnings', 'error',
        ]];
        yield 'schemas.available' => ['schemas.available', ['rule_schema', 'policy_schema']];
        yield 'rules.directory' => ['rules.directory', ['source', 'file_count']];
        yield 'rules.load' => ['rules.load', ['loaded', 'error']];
    }

    /**
     * @param list<string> $expectedKeys
     */
    #[DataProvider('detailKeySets')]
    public function testEveryCheckHasExactlyItsPinnedDetailKeySetInOrder(string $checkId, array $expectedKeys): void
    {
        $report = $this->runFixture('caret-8-2');

        self::assertSame($expectedKeys, array_keys($this->check($report, $checkId)->details));
    }

    // --- G-T12 -----------------------------------------------------------------------------------

    /** @return iterable<string, array{DiagnosticReport}> */
    public static function detailValueReports(): iterable
    {
        $runner = new DoctorRunner();

        yield 'caret-8-2' => [self::runFixtureWith($runner, 'caret-8-2')];
        yield 'no-composer-json' => [self::runFixtureWith($runner, 'no-composer-json')];
        yield 'malformed-composer-json' => [self::runFixtureWith($runner, 'malformed-composer-json')];
        yield 'malformed-composer-lock' => [self::runFixtureWith($runner, 'malformed-composer-lock')];
        yield 'non-string-constraint' => [self::runFixtureWith($runner, 'non-string-constraint')];
        yield 'conflict-empties-range' => [self::runFixtureWith($runner, 'conflict-empties-range')];

        $rulesDir = realpath(self::RULES_FIXTURES . '/invalid/filename-mismatch');
        self::assertIsString($rulesDir);
        yield 'filename-mismatch (single-line-message source)' => [self::runFixtureWith($runner, 'caret-8-2', $rulesDir)];

        // The schema-validation message for this fixture is genuinely multi-line (the pointer
        // lines are newline-joined by RuleLoader), so this row exercises D39's collapse for real.
        $multiLineRulesDir = realpath(self::RULES_FIXTURES . '/invalid/missing-required');
        self::assertIsString($multiLineRulesDir);
        yield 'missing-required (multi-line-message source)' => [self::runFixtureWith($runner, 'caret-8-2', $multiLineRulesDir)];
    }

    /**
     * Asserted against the actual encoded JSON (`json_decode` back into `mixed`), not against
     * `DiagnosticCheck::$details` directly — the PHPDoc-declared `string|null` value type on that
     * property is exactly the invariant this row exists to prove at runtime, so testing the encoded
     * output is what makes the assertion meaningful rather than a static-analysis tautology.
     */
    #[DataProvider('detailValueReports')]
    public function testEveryDetailValueIsStringOrNullAndSingleLine(DiagnosticReport $report): void
    {
        $decoded = json_decode((string) json_encode($report->toArray()), true);
        self::assertIsArray($decoded);

        $checks = $decoded['checks'] ?? null;
        self::assertIsArray($checks);

        foreach ($checks as $check) {
            self::assertIsArray($check);
            $id = $check['id'] ?? null;
            self::assertIsString($id);

            $details = $check['details'] ?? null;
            self::assertIsArray($details);

            foreach ($details as $key => $value) {
                self::assertIsString($key);
                self::assertTrue(
                    $value === null || is_string($value),
                    sprintf('%s.%s must be string|null, got %s.', $id, $key, get_debug_type($value)),
                );

                if (is_string($value)) {
                    self::assertStringNotContainsString("\n", $value, sprintf('%s.%s contains a newline.', $id, $key));
                    self::assertStringNotContainsString("\r", $value, sprintf('%s.%s contains a carriage return.', $id, $key));
                }
            }
        }
    }

    // --- G-T13 -----------------------------------------------------------------------------------

    public function testRunningTwiceOnTheSameFixtureIsByteIdentical(): void
    {
        $first = $this->runFixture('caret-8-2');
        $second = $this->runFixture('caret-8-2');

        self::assertSame(json_encode($first->toArray()), json_encode($second->toArray()));
    }

    // --- G-T14 -----------------------------------------------------------------------------------

    public function testReportConstructionRejectsAMissingCheckId(): void
    {
        $this->expectException(\LogicException::class);

        new DiagnosticReport([
            new DiagnosticCheck('cli.build', CheckStatus::Ok, 'x', ['version' => '0.1.0', 'distribution' => 'source']),
        ]);
    }

    public function testReportConstructionRejectsCheckIdsOutOfOrder(): void
    {
        $this->expectException(\LogicException::class);

        $checks = array_map(
            static fn(string $id): DiagnosticCheck => new DiagnosticCheck($id, CheckStatus::Ok, 'x', []),
            array_reverse(DoctorRunner::CHECK_IDS),
        );

        new DiagnosticReport($checks);
    }

    public function testReportConstructionRejectsAnEmptyCheckList(): void
    {
        $this->expectException(\LogicException::class);

        new DiagnosticReport([]);
    }

    // --- G-T28 -----------------------------------------------------------------------------------

    public function testPharGovernanceAcrossSrc(): void
    {
        $concatenated = '';
        foreach (self::collectPhpFiles(dirname(__DIR__, 3) . '/src') as $file) {
            $concatenated .= (string) file_get_contents($file);
        }

        self::assertSame(
            1,
            preg_match_all('/\\\\?Phar::running/', $concatenated),
            'Exactly one Phar::running call is permitted anywhere in src/ (§2.5).',
        );
        self::assertSame(
            0,
            preg_match_all('/new\s+\\\\?Phar\b/', $concatenated),
            'No `new Phar(...)` is permitted anywhere in src/ (§2.5).',
        );
    }

    // --- helpers ---------------------------------------------------------------------------------

    private function runFixture(string $fixture, ?string $rulesDir = null): DiagnosticReport
    {
        return self::runFixtureWith($this->runner, $fixture, $rulesDir);
    }

    private static function runFixtureWith(DoctorRunner $runner, string $fixture, ?string $rulesDir = null): DiagnosticReport
    {
        $path = realpath(self::FIXTURES . '/' . $fixture);
        self::assertIsString($path, sprintf('Fixture directory "%s" does not exist.', $fixture));

        return $runner->run(new PolicyRequest($path, ResolutionMode::RangeSafe), $rulesDir);
    }

    /** @return list<string> */
    private function ids(DiagnosticReport $report): array
    {
        return array_map(static fn(DiagnosticCheck $check): string => $check->id, $report->checks);
    }

    private function check(DiagnosticReport $report, string $id): DiagnosticCheck
    {
        foreach ($report->checks as $check) {
            if ($check->id === $id) {
                return $check;
            }
        }

        self::fail(sprintf('No check with id "%s" in the report.', $id));
    }

    private function allDetailValuesNull(DiagnosticCheck $check): bool
    {
        foreach ($check->details as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function collectPhpFiles(string $dir): array
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }

        sort($entries, SORT_STRING);

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                array_push($files, ...self::collectPhpFiles($path));
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }
}

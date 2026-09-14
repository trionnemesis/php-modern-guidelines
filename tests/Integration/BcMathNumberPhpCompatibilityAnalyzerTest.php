<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Tests\Support\FixtureTreeSnapshot;
use ModernPhpGuidelines\Verification\Process\NativeProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

#[Group('real-analyzer')]
final class BcMathNumberPhpCompatibilityAnalyzerTest extends TestCase
{
    private const PROJECT_PATH = __DIR__ . '/../fixtures/verification/projects/phpcompatibility-bcmath-number';

    private string $executable = '';

    /** @return iterable<string, array{string, list<string>, int}> */
    public static function floorCases(): iterable
    {
        yield 'floor 8.2' => ['>=8.2 <8.6', ['8.2', '8.3', '8.4', '8.5'], 1];
        yield 'floor 8.3' => ['>=8.3 <8.6', ['8.3', '8.4', '8.5'], 1];
        yield 'floor 8.4' => ['>=8.4 <8.6', ['8.4', '8.5'], 0];
        yield 'floor 8.5' => ['>=8.5 <8.6', ['8.5'], 0];
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
    }

    /** @param list<string> $expectedMinors */
    #[DataProvider('floorCases')]
    public function testFloorBoundaryIsMeasuredByTheRealAnalyzer(
        string $phpConstraint,
        array $expectedMinors,
        int $expectedFindingCount,
    ): void {
        $project = realpath(self::PROJECT_PATH);
        self::assertIsString($project);
        $before = FixtureTreeSnapshot::capture($project);

        $application = ApplicationFactory::create();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $tester = new ApplicationTester($application);
        $exitCode = $tester->run(
            [
                'command' => 'verify',
                'adapter' => 'phpcompatibility',
                '--executable' => $this->executable,
                '--project-root' => $project,
                '--php' => $phpConstraint,
                '--json' => true,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(
            $expectedFindingCount === 0 ? ExitCode::SUCCESS : ExitCode::VERIFICATION_FINDINGS,
            $exitCode,
            $tester->getErrorOutput(),
        );
        self::assertSame('', $tester->getErrorOutput());

        /** @var array<string, mixed> $report */
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expectedFindingCount, $report['summary']['finding_count']);

        $analysisInvocations = array_values(array_filter(
            $report['invocations'],
            static fn(array $invocation): bool => $invocation['purpose'] === 'analysis',
        ));
        self::assertCount(1, $analysisInvocations);
        self::assertSame($expectedMinors, $analysisInvocations[0]['policy_minors']);

        if ($expectedFindingCount === 1) {
            self::assertCount(1, $report['findings']);
            self::assertSame(
                'PHPCompatibility.Classes.NewClasses.bcmath_numberFound',
                $report['findings'][0]['external_rule_id'],
            );
            self::assertSame('mapped', $report['findings'][0]['mapping_status']);
            self::assertSame(['extension.bcmath_number'], $report['findings'][0]['mapped_rule_ids']);
            self::assertSame(['extension.bcmath_number'], array_column($report['rule_contexts'], 'id'));
        } else {
            self::assertSame([], $report['findings']);
            self::assertSame([], $report['rule_contexts']);
        }

        self::assertSame($before, FixtureTreeSnapshot::capture($project));
    }
}

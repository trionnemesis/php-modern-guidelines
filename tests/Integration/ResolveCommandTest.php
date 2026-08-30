<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ResolveCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/projects';
    private const POLICY_GOLDEN = __DIR__ . '/../fixtures/policy';
    private const CLI_GOLDEN = __DIR__ . '/../fixtures/cli';

    /**
     * @param array<string, string> $extraArgs
     */
    #[DataProvider('cases')]
    public function testJsonOutputMatchesThePolicyGolden(string $fixture, array $extraArgs, string $golden): void
    {
        $fixtureRealPath = $this->realFixture($fixture);

        $tester = $this->tester();
        $exitCode = $tester->run(
            array_merge(['command' => 'resolve', '--project-root' => $fixtureRealPath, '--json' => true], $extraArgs),
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('', $tester->getErrorOutput());

        $expected = $this->goldenContents(self::POLICY_GOLDEN . '/' . $golden . '.json', $fixtureRealPath);
        self::assertSame($expected, $tester->getDisplay());
    }

    /**
     * @param array<string, string> $extraArgs
     */
    #[DataProvider('cases')]
    public function testHumanOutputMatchesTheCliGolden(string $fixture, array $extraArgs, string $golden): void
    {
        $fixtureRealPath = $this->realFixture($fixture);

        $tester = $this->tester();
        $exitCode = $tester->run(
            array_merge(['command' => 'resolve', '--project-root' => $fixtureRealPath], $extraArgs),
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('', $tester->getErrorOutput());

        $expected = $this->goldenContents(self::CLI_GOLDEN . '/resolve-' . $golden . '.txt', $fixtureRealPath);
        self::assertSame($expected, $tester->getDisplay());
    }

    public function testLegacyOnlyExitsFourWithEmptyStdout(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'resolve', '--project-root' => $this->realFixture('legacy-only'), '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::UNRESOLVABLE_POLICY, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('allows no PHP minor known to this tool', $tester->getErrorOutput());
    }

    public function testFutureOnlyExitsFourWithEmptyStdout(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'resolve', '--project-root' => $this->realFixture('future-only'), '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::UNRESOLVABLE_POLICY, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('allows no PHP minor known to this tool', $tester->getErrorOutput());
    }

    public function testConflictEmptiesRangeExitsFour(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'resolve', '--project-root' => $this->realFixture('conflict-empties-range'), '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::UNRESOLVABLE_POLICY, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('allows no PHP minor known to this tool', $tester->getErrorOutput());
    }

    #[DataProvider('malformedCases')]
    public function testMalformedInputExitsTwoWithEmptyStdoutAndNamesTheFile(string $fixture, string $expectedSubstring): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'resolve', '--project-root' => $this->realFixture($fixture), '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString($expectedSubstring, $tester->getErrorOutput());
    }

    public function testModeSingleTargetOnCaretEightTwo(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'resolve', '--project-root' => $fixtureRealPath, '--mode' => 'single-target', '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        $expected = $this->goldenContents(self::POLICY_GOLDEN . '/caret-8-2--mode-single-target.json', $fixtureRealPath);
        self::assertSame($expected, $tester->getDisplay());
    }

    public function testModeRuntimeObservedOnCaretEightTwoIsStructurallyValidWithNoGolden(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'resolve', '--project-root' => $fixtureRealPath, '--mode' => 'runtime-observed', '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame([PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION], $decoded['allowed_minors']);
        self::assertSame('observed', $decoded['confidence']);
    }

    public function testPhpOverrideOnCaretEightTwo(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'resolve', '--project-root' => $fixtureRealPath, '--php' => '8.4', '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        $expected = $this->goldenContents(self::POLICY_GOLDEN . '/caret-8-2--php-8-4.json', $fixtureRealPath);
        self::assertSame($expected, $tester->getDisplay());
    }

    public function testRunningResolveTwiceOnTheSameFixtureIsByteIdentical(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $first = $this->tester();
        $first->run(['command' => 'resolve', '--project-root' => $fixtureRealPath, '--json' => true], ['decorated' => false]);

        $second = $this->tester();
        $second->run(['command' => 'resolve', '--project-root' => $fixtureRealPath, '--json' => true], ['decorated' => false]);

        self::assertSame($first->getDisplay(), $second->getDisplay());
    }

    #[DataProvider('allFixtureDirectories')]
    public function testResolveNeverWritesToTheAnalyzedProjectDirectory(string $fixture): void
    {
        $fixtureRealPath = $this->realFixture($fixture);
        $before = $this->snapshot($fixtureRealPath);

        $tester = $this->tester();
        $tester->run(
            ['command' => 'resolve', '--project-root' => $fixtureRealPath, '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        $after = $this->snapshot($fixtureRealPath);

        self::assertSame($before, $after, sprintf('Fixture directory "%s" changed after running resolve.', $fixture));
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function cases(): iterable
    {
        yield 'A caret-8-2' => ['caret-8-2', [], 'caret-8-2'];
        yield 'B caret-8-4' => ['caret-8-4', [], 'caret-8-4'];
        yield 'C tilde-patch' => ['tilde-patch', [], 'tilde-patch'];
        yield 'D tilde-minor' => ['tilde-minor', [], 'tilde-minor'];
        yield 'E comparison-range' => ['comparison-range', [], 'comparison-range'];
        yield 'F or-constraint' => ['or-constraint', [], 'or-constraint'];
        yield 'G exact-version' => ['exact-version', [], 'exact-version'];
        yield 'H open-upper-unbounded' => ['open-upper-unbounded', [], 'open-upper-unbounded'];
        yield 'I below-known-min' => ['below-known-min', [], 'below-known-min'];
        yield 'L no-php-constraint' => ['no-php-constraint', [], 'no-php-constraint'];
        yield 'M no-composer-json' => ['no-composer-json', [], 'no-composer-json'];
        yield 'N platform-override' => ['platform-override', [], 'platform-override'];
        yield 'O platform-override-conflict' => ['platform-override-conflict', [], 'platform-override-conflict'];
        yield 'P lock-platform-override' => ['lock-platform-override', [], 'lock-platform-override'];
        yield 'Q or-hole' => ['or-hole', [], 'or-hole'];
        yield 'R patch-exclusion' => ['patch-exclusion', [], 'patch-exclusion'];
        yield 'S caret-8-2 --php 8.4' => ['caret-8-2', ['--php' => '8.4'], 'caret-8-2--php-8-4'];
        yield 'U caret-8-2 --mode=single-target' => ['caret-8-2', ['--mode' => 'single-target'], 'caret-8-2--mode-single-target'];
        yield 'V tilde-patch --mode=single-target' => ['tilde-patch', ['--mode' => 'single-target'], 'tilde-patch--mode-single-target'];
        yield 'X conflict-php' => ['conflict-php', [], 'conflict-php'];
        yield 'Y lock-platform-override-conflict' => ['lock-platform-override-conflict', [], 'lock-platform-override-conflict'];
        yield 'Z1 platform-disabled' => ['platform-disabled', [], 'platform-disabled'];
        yield 'Z2 lock-mismatch' => ['lock-mismatch', [], 'lock-mismatch'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedCases(): iterable
    {
        yield 'malformed-composer-json' => ['malformed-composer-json', 'composer.json'];
        yield 'malformed-composer-lock' => ['malformed-composer-lock', 'composer.lock'];
        yield 'bad-platform-value' => ['bad-platform-value', 'config.platform.php'];
        yield 'unparseable-constraint' => ['unparseable-constraint', 'composer.json'];
        yield 'non-string-constraint' => ['non-string-constraint', 'require.php'];
        yield 'unparseable-conflict' => ['unparseable-conflict', 'composer.json'];
    }

    /** @return iterable<string, array{string}> */
    public static function allFixtureDirectories(): iterable
    {
        foreach (self::cases() as $name => $case) {
            yield $name => [$case[0]];
        }

        yield 'legacy-only' => ['legacy-only'];
        yield 'future-only' => ['future-only'];
        yield 'conflict-empties-range' => ['conflict-empties-range'];

        foreach (self::malformedCases() as $name => $case) {
            yield $name => [$case[0]];
        }
    }

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

    private function goldenContents(string $path, string $fixtureRealPath): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, sprintf('Could not read golden file %s.', $path));

        return str_replace('@PROJECT_ROOT@', $fixtureRealPath, $contents);
    }

    /** @return array<string, string> path => md5 */
    private function snapshot(string $directory): array
    {
        $map = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $hash = md5_file($file->getPathname());
            self::assertIsString($hash);
            $map[$file->getPathname()] = $hash;
        }

        ksort($map);

        return $map;
    }
}

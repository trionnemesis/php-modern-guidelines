<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit;

use ModernPhpGuidelines\Support\JsonFile;
use PHPUnit\Framework\TestCase;

/**
 * WORK-ORDER §3.7 (E-T1..E-T18): consistency assertions over box.json.dist, .gitignore and both
 * workflows. Reads committed text only; never invokes box; never requires build/ to exist (§2.8).
 */
final class PharBuildConfigTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function boxConfig(): array
    {
        return JsonFile::readArray($this->root() . '/box.json.dist', 'box.json.dist');
    }

    public function testBoxConfigExistsAndDecodes(): void
    {
        $config = $this->boxConfig();

        self::assertNotSame([], $config);
    }

    public function testMainEntryPointComesFromComposerBin(): void
    {
        // "main" is omitted from box.json.dist: box 4.7.0 derives it from composer.json's
        // "bin" entry, and setting it explicitly to that same value makes `box validate`
        // exit 1 with a "can be omitted" recommendation (CI run 33321877654).
        $config = $this->boxConfig();
        self::assertArrayNotHasKey('main', $config);

        $composer = JsonFile::readArray($this->root() . '/composer.json', 'composer.json');
        self::assertSame(['bin/php-modern-guidelines'], $composer['bin']);
        self::assertFileExists($this->root() . '/bin/php-modern-guidelines');
    }

    public function testOutputPath(): void
    {
        $config = $this->boxConfig();

        self::assertSame('build/php-modern-guidelines.phar', $config['output']);
    }

    public function testAlias(): void
    {
        $config = $this->boxConfig();

        self::assertSame('php-modern-guidelines.phar', $config['alias']);
        self::assertStringEndsWith('.phar', $config['alias']);
    }

    public function testDefaultValuedKeysAreOmittedSoBoxValidateStaysClean(): void
    {
        // box 4.7.0's `box validate` exits 1 when a setting is explicitly set to its default
        // value ("passed the validation with recommendations", CI run 33321877654). The build
        // therefore relies on box's defaults for these settings — main from composer.json bin,
        // check-requirements on, dev files and composer files excluded, autoload dumped,
        // compression NONE, chmod 0755, algorithm SHA512 — and this guard keeps the config
        // recommendation-free.
        $config = $this->boxConfig();

        $defaultValued = [
            'main',
            'chmod',
            'algorithm',
            'compression',
            'check-requirements',
            'dump-autoload',
            'exclude-composer-files',
            'exclude-dev-files',
        ];

        foreach ($defaultValued as $key) {
            self::assertArrayNotHasKey(
                $key,
                $config,
                sprintf('box.json.dist must omit default-valued key "%s" or `box validate` exits 1.', $key),
            );
        }
    }

    public function testDirectoriesAreBundledAndExist(): void
    {
        $config = $this->boxConfig();

        self::assertSame(['resources/rules', 'schemas'], $config['directories']);

        $directories = ['resources/rules', 'schemas'];
        foreach ($directories as $directory) {
            self::assertDirectoryExists($this->root() . '/' . $directory);
        }
    }

    public function testNoGitOrReplacementsKey(): void
    {
        $config = $this->boxConfig();

        $forbidden = ['git', 'git-version', 'git-tag', 'git-commit', 'git-commit-short', 'replacements'];

        foreach ($forbidden as $key) {
            self::assertArrayNotHasKey($key, $config, sprintf('box.json.dist must not contain key "%s".', $key));
        }
    }

    public function testBannerCarriesNoDateAndNoVersion(): void
    {
        $config = $this->boxConfig();
        $banner = $config['banner'];

        self::assertIsString($banner);
        self::assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $banner);
        self::assertStringNotContainsString((string) (int) date('Y'), $banner);
    }

    public function testGitignoreHasBuildAndComposerLockLines(): void
    {
        $lines = $this->fileLines('.gitignore');

        self::assertContains('/build/', $lines);

        $composerLockLines = array_values(array_filter(
            $lines,
            static fn(string $line): bool => '/composer.lock' === $line,
        ));
        self::assertCount(1, $composerLockLines);
    }

    public function testCiWorkflowContainsPharJobEssentials(): void
    {
        $yaml = $this->fileContents('.github/workflows/ci.yml');

        self::assertStringContainsString('box:4.7.0', $yaml);
        self::assertStringContainsString('phar.readonly=0', $yaml);
        self::assertStringContainsString('box validate box.json.dist', $yaml);
        self::assertStringContainsString('--no-dev', $yaml);
        self::assertStringContainsString('build/php-modern-guidelines.phar', $yaml);
        self::assertStringContainsString('list-rules --project-root=', $yaml);
        self::assertStringContainsString('explain language.property_hooks', $yaml);
    }

    public function testComposerProductionDependenciesExcludeVerificationAnalyzers(): void
    {
        $composer = JsonFile::readArray($this->root() . '/composer.json', 'composer.json');
        $require = $composer['require'] ?? null;
        self::assertIsArray($require);

        $forbidden = [
            'phpcompatibility/php-compatibility',
            'squizlabs/php_codesniffer',
            'phpstan/phpstan-deprecation-rules',
            'rector/rector',
        ];

        foreach ($forbidden as $package) {
            self::assertArrayNotHasKey(
                $package,
                $require,
                sprintf('Analyzer package "%s" must not be a production dependency.', $package),
            );
        }
    }

    public function testCiWorkflowSmokeTestsTruthfulUnavailableVerificationWithoutBundledAnalyzers(): void
    {
        $yaml = $this->fileContents('.github/workflows/ci.yml');

        foreach ([
            'phpcompatibility/php-compatibility',
            'squizlabs/php_codesniffer',
            'phpstan/phpstan-deprecation-rules',
            'rector/rector',
        ] as $package) {
            self::assertStringContainsString($package, $yaml);
        }

        self::assertStringContainsString('Composer\\InstalledVersions::isInstalled($package, false)', $yaml);
        self::assertStringContainsString("new Phar('build/php-modern-guidelines.phar')", $yaml);
        self::assertStringContainsString("=== 'FakeVerificationAdapter.php'", $yaml);
        self::assertStringContainsString("file_get_contents('schemas/verification.schema.json')", $yaml);
        self::assertStringContainsString("'/schemas/verification.schema.json'", $yaml);
        self::assertStringContainsString('The PHAR must contain the exact canonical verification schema.', $yaml);
        self::assertStringContainsString('missing_executable=/definitely/not-installed/phpcs', $yaml);
        self::assertStringContainsString('php "$phar" verify phpcompatibility', $yaml);
        self::assertStringContainsString('--executable="$missing_executable"', $yaml);
        self::assertStringContainsString('--project-root="$fixture"', $yaml);
        self::assertStringContainsString('> "$verify_stdout" 2> "$verify_stderr"', $yaml);
        self::assertStringContainsString('if [[ "$verify_exit" -ne 7 ]]', $yaml);
        self::assertStringContainsString('if [[ -s "$verify_stderr" ]]', $yaml);
        self::assertStringContainsString("'status'] ?? null) === 'unavailable'", $yaml);
        self::assertStringContainsString("'output_version'] ?? null) === '1.0.0'", $yaml);
        self::assertStringContainsString("'exit_code'] ?? null) === 7", $yaml);
        self::assertStringContainsString("'projection_status'] ?? null) === 'not_evaluated'", $yaml);
        self::assertStringContainsString("'planned_invocations'] ?? null) === []", $yaml);
        self::assertStringContainsString("'adapter.executable_unavailable'", $yaml);
        self::assertStringContainsString("!str_contains(\$raw, \$missingExecutable)", $yaml);
        self::assertStringContainsString('PackagePaths::verificationSchemaPath()', $yaml);
        self::assertStringContainsString('->validate($reportObject) === []', $yaml);

        foreach ([
            'invocation_count',
            'finding_count',
            'mapped_finding_count',
            'unmapped_finding_count',
            'mapping_count',
            'mapped_rule_count',
        ] as $countKey) {
            self::assertStringContainsString(sprintf("'%s' => 0", $countKey), $yaml);
        }
    }

    /**
     * E-T11a: the doctor hand-off guard. Implemented exactly as pinned in WORK-ORDER §3.7 — never with
     * assertStringContainsString, because the marker comment quotes the command it stands in for, which
     * makes a substring search true in both states and therefore vacuous.
     */
    public function testCiWorkflowDoctorHandoffIsExactlyOneOfCommandOrMarker(): void
    {
        $yaml = $this->fileContents('.github/workflows/ci.yml');
        $lines = explode("\n", $yaml);

        $stepIndex = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, '- name: Smoke-test the PHAR')) {
                $stepIndex = $index;

                break;
            }
        }
        self::assertIsInt($stepIndex, 'Could not locate the "Smoke-test the PHAR" step.');

        $stepIndentation = strlen($lines[$stepIndex]) - strlen(ltrim($lines[$stepIndex]));

        $runIndex = null;
        for ($i = $stepIndex + 1; $i < count($lines); ++$i) {
            if (str_contains($lines[$i], 'run: |')) {
                $runIndex = $i;

                break;
            }
        }
        self::assertIsInt($runIndex, 'Could not locate the "run: |" line of the "Smoke-test the PHAR" step.');

        $body = [];
        for ($i = $runIndex + 1; $i < count($lines); ++$i) {
            $currentLine = $lines[$i];
            if ('' === trim($currentLine)) {
                $body[] = $currentLine;

                continue;
            }

            $indentation = strlen($currentLine) - strlen(ltrim($currentLine));
            if ($indentation <= $stepIndentation) {
                break;
            }

            $body[] = $currentLine;
        }

        $trimmedBody = array_map(static fn(string $line): string => trim($line), $body);

        $command = 'php "$phar" doctor --project-root="$fixture" --json > /dev/null';
        $marker = '# Slice G replaces this line with: ' . $command;

        $hasCommand = in_array($command, $trimmedBody, true);
        $hasMarker = in_array($marker, $trimmedBody, true);

        self::assertNotSame(
            $hasCommand,
            $hasMarker,
            'Exactly one of the doctor command line or its marker comment must be present.',
        );
    }

    public function testReleaseWorkflowGuardAndChangelogExtractionUnchanged(): void
    {
        $yaml = $this->fileContents('.github/workflows/release.yml');

        self::assertStringContainsString(
            "if: startsWith(github.event.head_commit.message, 'release:')",
            $yaml,
        );
        self::assertStringContainsString('awk -v header="## [$VERSION]"', $yaml);
    }

    public function testReleaseWorkflowAttachesBothAssets(): void
    {
        $yaml = $this->fileContents('.github/workflows/release.yml');

        self::assertStringContainsString('build/php-modern-guidelines.phar', $yaml);
        self::assertStringContainsString('build/php-modern-guidelines.phar.sha256', $yaml);
        self::assertStringContainsString('sha256sum php-modern-guidelines.phar', $yaml);
    }

    public function testAllWorkflowUsesLinesArePinnedBySha(): void
    {
        foreach (['ci.yml', 'pages.yml', 'release.yml'] as $workflow) {
            $lines = $this->fileLines('.github/workflows/' . $workflow);

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!str_starts_with($trimmed, 'uses:') && !str_starts_with($trimmed, '- uses:')) {
                    continue;
                }

                self::assertMatchesRegularExpression(
                    '/@[0-9a-f]{40} # v\d+$/',
                    $trimmed,
                    sprintf('%s line "%s" is not pinned by full commit SHA plus "# vN".', $workflow, $trimmed),
                );
            }
        }
    }

    public function testAdrSevenExistsAndReferencesBoxAndAdrThree(): void
    {
        $contents = $this->fileContents('docs/adr/ADR-007-phar-build-and-distribution.md');

        self::assertStringContainsString('Status: Accepted', $contents);
        self::assertStringContainsString('4.7.0', $contents);
        self::assertStringContainsString('ADR-003', $contents);
    }

    public function testAdrSevenDisclosesPackagistIsNotPublished(): void
    {
        $contents = $this->fileContents('docs/adr/ADR-007-phar-build-and-distribution.md');

        self::assertStringContainsString('Packagist', $contents);
    }

    public function testAdrSevenDisclosesComposerLockIsNotPinnedAcrossBuilds(): void
    {
        $contents = $this->fileContents('docs/adr/ADR-007-phar-build-and-distribution.md');

        self::assertStringContainsString('composer.lock', $contents);
        self::assertStringContainsString('Dependency resolution is not pinned across builds', $contents);
    }

    public function testPhpstanNeonListsTools(): void
    {
        $contents = $this->fileContents('phpstan.neon');

        self::assertMatchesRegularExpression('/^\s*-\s*tools\s*$/m', $contents);
    }

    public function testReleaseWorkflowVerifiesFullDataTreeSurface(): void
    {
        $yaml = $this->fileContents('.github/workflows/release.yml');

        // The verification step must exercise version, resolve, list-rules, explain and doctor — not
        // just version and resolve — so an archive missing its bundled data trees cannot be published.
        self::assertStringContainsString('php "$phar" version', $yaml);
        self::assertStringContainsString('php "$phar" resolve --project-root="$fixture" --json', $yaml);
        self::assertStringContainsString(
            'php "$phar" list-rules --project-root="$fixture" --kind=deprecated',
            $yaml,
        );
        self::assertStringContainsString(
            'php "$phar" explain language.property_hooks --project-root="$fixture" --json',
            $yaml,
        );
        self::assertStringContainsString(
            'php "$phar" doctor --project-root="$fixture" --json',
            $yaml,
        );
    }

    private function fileContents(string $relativePath): string
    {
        $contents = file_get_contents($this->root() . '/' . $relativePath);
        self::assertIsString($contents, sprintf('Could not read %s.', $relativePath));

        return $contents;
    }

    /**
     * @return list<string>
     */
    private function fileLines(string $relativePath): array
    {
        return explode("\n", $this->fileContents($relativePath));
    }
}

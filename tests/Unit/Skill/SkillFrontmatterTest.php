<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Skill;

use PHPUnit\Framework\TestCase;

/**
 * Frontmatter shape and limits, directory/name agreement, file-set presence, body size.
 * WORK-ORDER.md §4.6, rows F-T1 through F-T12.
 */
final class SkillFrontmatterTest extends TestCase
{
    private const SKILL_ROOT = __DIR__ . '/../../../skills';
    private const SKILL_MD = self::SKILL_ROOT . '/php-modern-guidelines/SKILL.md';
    private const CLI_CONTRACT = self::SKILL_ROOT . '/php-modern-guidelines/references/cli-contract.md';
    private const TWO_AXIS = self::SKILL_ROOT . '/php-modern-guidelines/references/two-axis-policy.md';
    private const SNIPPET = self::SKILL_ROOT . '/agents-md/SNIPPET.md';
    private const FIXTURES_ROOT = __DIR__ . '/../../fixtures';

    /** The skill file set of WORK-ORDER.md §4.1, exactly these four files. */
    private const FILE_SET = [self::SKILL_MD, self::CLI_CONTRACT, self::TWO_AXIS, self::SNIPPET];

    /** WORK-ORDER.md §4.2's pinned `##` heading order for the `SKILL.md` body. */
    private const PINNED_HEADINGS = [
        '## What this tool is',
        '## When to use it',
        '## The two-axis rule',
        '## Required workflow',
        '## Commands',
        '## Exit codes',
        '## Hard limits',
        '## Installing the tool',
        '## Reference files',
    ];

    // --- F-T1 --------------------------------------------------------------------------------

    public function testAllFourSkillFilesExistAndAreReadable(): void
    {
        foreach (self::FILE_SET as $path) {
            self::assertFileExists($path);
            self::assertFileIsReadable($path);
        }
    }

    // --- F-T2 --------------------------------------------------------------------------------

    public function testFrontmatterIsExactlyThreePinnedLinesInOrder(): void
    {
        $lines = self::readLines(self::SKILL_MD);

        self::assertSame('---', $lines[0] ?? null, 'SKILL.md must start with a "---" frontmatter fence.');

        $closingIndex = null;
        for ($i = 1; $i < count($lines); $i++) {
            if ($lines[$i] === '---') {
                $closingIndex = $i;

                break;
            }
        }

        self::assertNotNull($closingIndex, 'SKILL.md frontmatter must have a closing "---" line.');

        $frontmatterLines = array_slice($lines, 1, $closingIndex - 1);
        self::assertCount(3, $frontmatterLines, 'SKILL.md frontmatter must contain exactly three lines.');

        $expectedKeysInOrder = ['name', 'description', 'license'];
        foreach ($frontmatterLines as $i => $line) {
            self::assertMatchesRegularExpression(
                '/^(name|description|license): (.+)$/',
                $line,
                sprintf('Frontmatter line %d does not match "key: value".', $i + 1),
            );
            [$key] = explode(':', $line, 2);
            self::assertSame($expectedKeysInOrder[$i], $key, sprintf('Frontmatter key at line %d is out of order.', $i + 1));
        }
    }

    // --- F-T3 --------------------------------------------------------------------------------

    public function testNameIsValidKebabCase(): void
    {
        $name = self::frontmatterValue('name');

        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $name);
        self::assertStringStartsNotWith('-', $name);
        self::assertStringEndsNotWith('-', $name);
        self::assertStringNotContainsString('--', $name);
        self::assertLessThanOrEqual(64, strlen($name));
    }

    // --- F-T4 --------------------------------------------------------------------------------

    public function testNameEqualsDirectoryBasename(): void
    {
        $name = self::frontmatterValue('name');

        self::assertSame(basename(dirname(self::SKILL_MD)), $name);
    }

    // --- F-T5 --------------------------------------------------------------------------------

    public function testDescriptionLimits(): void
    {
        $description = self::frontmatterValue('description');

        self::assertLessThanOrEqual(1024, strlen($description));
        self::assertStringNotContainsString('<', $description);
        self::assertStringNotContainsString('>', $description);
    }

    // --- F-T6 --------------------------------------------------------------------------------

    public function testDescriptionNamesAllFourCommandsAndATriggerPhrase(): void
    {
        $description = self::frontmatterValue('description');

        foreach (['resolve', 'list-rules', 'explain', 'doctor'] as $command) {
            self::assertStringContainsString($command, $description, sprintf('description must name "%s".', $command));
        }

        self::assertStringContainsString('Use this when', $description);
    }

    // --- F-T7 --------------------------------------------------------------------------------

    public function testSkillMdBodyIsAtMost500Lines(): void
    {
        self::assertLessThanOrEqual(500, count(self::skillMdBodyLines()));
    }

    // --- F-T8 --------------------------------------------------------------------------------

    public function testSkillMdBodyContainsPinnedHeadingsInOrder(): void
    {
        $body = self::skillMdBodyLines();

        $foundPositions = [];
        foreach (self::PINNED_HEADINGS as $heading) {
            $position = array_search($heading, $body, true);
            self::assertNotFalse($position, sprintf('Missing pinned heading "%s".', $heading));
            $foundPositions[] = $position;
        }

        $sorted = $foundPositions;
        sort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $foundPositions, 'Pinned headings are not in the required order.');
    }

    // --- F-T9 --------------------------------------------------------------------------------

    public function testCliContractHasThePinnedHeadings(): void
    {
        $contents = (string) file_get_contents(self::CLI_CONTRACT);

        self::assertStringContainsString("### exit codes\n", $contents);
        self::assertStringContainsString("### resolve --json keys\n", $contents);
    }

    // --- F-T10 -------------------------------------------------------------------------------

    public function testSnippetHasExactlyOneMarkdownFencedBlockOpeningWithThePolicyHeading(): void
    {
        $contents = (string) file_get_contents(self::SNIPPET);

        preg_match_all('/^```([a-z]*)\n(.*?)^```$/ms', $contents, $matches, PREG_SET_ORDER);

        $markdownBlocks = array_values(array_filter($matches, static fn(array $m): bool => $m[1] === 'markdown'));

        self::assertCount(1, $markdownBlocks, 'SNIPPET.md must contain exactly one ```markdown fenced block.');
        self::assertStringContainsString('## PHP version policy', $markdownBlocks[0][2]);
    }

    // --- F-T11 -------------------------------------------------------------------------------

    public function testPlaceholderConventionsDoNotBleedBetweenSurfaces(): void
    {
        foreach (self::FILE_SET as $path) {
            $contents = (string) file_get_contents($path);
            self::assertStringNotContainsString('@PROJECT_ROOT@', $contents, sprintf('%s must not use the goldens\' @PROJECT_ROOT@ token.', $path));
            self::assertStringNotContainsString('@VERSION@', $contents, sprintf('%s must not use the goldens\' @VERSION@ token.', $path));
        }

        foreach (self::fixtureFiles() as $path) {
            $contents = (string) file_get_contents($path);
            self::assertStringNotContainsString('/path/to/app', $contents, sprintf('%s must not use the skill\'s /path/to/app token.', $path));
            self::assertStringNotContainsString('<app>', $contents, sprintf('%s must not use the skill\'s <app> token.', $path));
            self::assertStringNotContainsString('<version>', $contents, sprintf('%s must not use the skill\'s <version> token.', $path));
        }
    }

    // --- F-T12 -------------------------------------------------------------------------------

    public function testNoOutOfScopeGuardStrings(): void
    {
        foreach (self::FILE_SET as $path) {
            $contents = (string) file_get_contents($path);

            self::assertStringNotContainsStringIgnoringCase('marketplace', $contents, sprintf('%s must not mention a marketplace manifest.', $path));
            self::assertStringNotContainsStringIgnoringCase('plugin manifest', $contents, sprintf('%s must not mention a plugin manifest.', $path));

            foreach (self::readLines($path) as $line) {
                self::assertDoesNotMatchRegularExpression(
                    '/^allowed-tools:/',
                    $line,
                    sprintf('%s must not declare an "allowed-tools" frontmatter key.', $path),
                );
            }
        }
    }

    // --- helpers -------------------------------------------------------------------------------

    /** @return list<string> */
    private static function readLines(string $path): array
    {
        $contents = (string) file_get_contents($path);

        return explode("\n", rtrim($contents, "\n"));
    }

    /** @return list<string> the SKILL.md body lines, i.e. everything after the closing frontmatter "---" */
    private static function skillMdBodyLines(): array
    {
        $lines = self::readLines(self::SKILL_MD);

        $closingIndex = null;
        for ($i = 1; $i < count($lines); $i++) {
            if ($lines[$i] === '---') {
                $closingIndex = $i;

                break;
            }
        }

        self::assertNotNull($closingIndex);

        return array_slice($lines, $closingIndex + 1);
    }

    private static function frontmatterValue(string $key): string
    {
        $lines = self::readLines(self::SKILL_MD);

        foreach ($lines as $line) {
            if (str_starts_with($line, $key . ': ')) {
                return substr($line, strlen($key) + 2);
            }
        }

        self::fail(sprintf('Frontmatter key "%s" not found.', $key));
    }

    /** @return list<string> every file under tests/fixtures/, recursively */
    private static function fixtureFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::FIXTURES_ROOT, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}

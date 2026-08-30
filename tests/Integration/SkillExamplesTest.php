<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Executes every `console` fenced block in the skill file set and compares its captured display,
 * byte-for-byte, against the block's own expected body. WORK-ORDER.md §4.8, rows F-T23 through F-T26.
 */
final class SkillExamplesTest extends TestCase
{
    private const SKILL_ROOT = __DIR__ . '/../../skills';
    private const SKILL_MD = self::SKILL_ROOT . '/php-modern-guidelines/SKILL.md';
    private const CLI_CONTRACT = self::SKILL_ROOT . '/php-modern-guidelines/references/cli-contract.md';
    private const TWO_AXIS = self::SKILL_ROOT . '/php-modern-guidelines/references/two-axis-policy.md';
    private const SNIPPET = self::SKILL_ROOT . '/agents-md/SNIPPET.md';

    /** @var list<string> */
    private const FILE_SET = [self::SKILL_MD, self::CLI_CONTRACT, self::TWO_AXIS, self::SNIPPET];

    private const FIXTURE = __DIR__ . '/../fixtures/projects/caret-8-2';

    /** The four required `$` lines of WORK-ORDER.md §4.8. */
    private const REQUIRED_DOLLAR_LINES = [
        '$ php bin/php-modern-guidelines resolve --project-root=/path/to/app',
        '$ php bin/php-modern-guidelines list-rules --project-root=/path/to/app --kind=deprecated',
        '$ php bin/php-modern-guidelines explain language.property_hooks --project-root=/path/to/app',
        '$ php bin/php-modern-guidelines doctor --project-root=/path/to/app',
    ];

    private static ?Application $application = null;

    private static function application(): Application
    {
        if (self::$application === null) {
            $application = ApplicationFactory::create();
            $application->setAutoExit(false);
            $application->setCatchExceptions(false);
            self::$application = $application;
        }

        return self::$application;
    }

    private static function fixtureRealPath(): string
    {
        $path = realpath(self::FIXTURE);
        self::assertIsString($path, 'Fixture directory tests/fixtures/projects/caret-8-2 does not exist.');

        return $path;
    }

    /** @return array<string, InputOption> */
    private static function optionsOf(InputDefinition $definition): array
    {
        /** @var array<string, InputOption> $options */
        $options = $definition->getOptions();

        return $options;
    }

    /** @return array<string, Command> keyed by every command name and alias */
    private static function commandsByNameOrAlias(): array
    {
        /** @var array<string, Command> $own */
        $own = array_filter(
            self::application()->all(),
            static fn(Command $c): bool => str_starts_with($c::class, 'ModernPhpGuidelines\\Command\\'),
        );

        return $own;
    }

    /**
     * Every `console` fenced block across the skill file set, in file order.
     *
     * @return list<array{file: string, dollarLine: string, expectedBody: string}>
     */
    public static function consoleBlocks(): array
    {
        $blocks = [];

        foreach (self::FILE_SET as $path) {
            $contents = (string) file_get_contents($path);

            preg_match_all('/^```console\n(.*?)^```$/ms', $contents, $matches);

            foreach ($matches[1] as $body) {
                $lines = explode("\n", rtrim($body, "\n"));
                $dollarLine = array_shift($lines);
                $expectedBody = $lines === [] ? '' : implode("\n", $lines) . "\n";

                $blocks[] = ['file' => $path, 'dollarLine' => $dollarLine, 'expectedBody' => $expectedBody];
            }
        }

        return $blocks;
    }

    /** @return list<array{0: array{file: string, dollarLine: string, expectedBody: string}}> */
    public static function consoleBlockCases(): array
    {
        return array_map(static fn(array $block): array => [$block], self::consoleBlocks());
    }

    // --- F-T23 -----------------------------------------------------------------------------------

    public function testAtLeastFourConsoleBlocksExistAndTheFourRequiredDollarLinesArePresent(): void
    {
        $blocks = self::consoleBlocks();
        self::assertGreaterThanOrEqual(4, count($blocks));

        $dollarLines = array_map(static fn(array $b): string => $b['dollarLine'], $blocks);
        foreach (self::REQUIRED_DOLLAR_LINES as $required) {
            self::assertContains($required, $dollarLines, sprintf('Required example line missing: %s', $required));
        }
    }

    // --- F-T24 / F-T25 -----------------------------------------------------------------------------

    /** @param array{file: string, dollarLine: string, expectedBody: string} $block */
    #[DataProvider('consoleBlockCases')]
    public function testConsoleBlockExecutesAndMatchesItsExpectedBodyByteForByte(array $block): void
    {
        $fixtureRealPath = self::fixtureRealPath();

        $input = self::parseDollarLine($block['dollarLine'], $fixtureRealPath);

        $tester = new ApplicationTester(self::application());
        $exitCode = $tester->run($input, ['capture_stderr_separately' => true, 'decorated' => false]);

        self::assertSame(ExitCode::SUCCESS, $exitCode, sprintf('Block in %s did not exit 0: %s', $block['file'], $block['dollarLine']));
        self::assertSame('', $tester->getErrorOutput(), sprintf('Block in %s wrote to stderr: %s', $block['file'], $block['dollarLine']));

        $display = $tester->getDisplay();
        $display = str_replace($fixtureRealPath, '<app>', $display);

        $expectedVersionOccurrences = substr_count($block['expectedBody'], '<version>');
        $display = str_replace(ApplicationFactory::VERSION, '<version>', $display, $count);
        self::assertSame(
            $expectedVersionOccurrences,
            $count,
            sprintf(
                'Version substitution count mismatch for %s: expected %d occurrence(s) of the version string, replaced %d.',
                $block['dollarLine'],
                $expectedVersionOccurrences,
                $count,
            ),
        );

        self::assertSame($block['expectedBody'], $display, sprintf('Output mismatch for: %s (in %s)', $block['dollarLine'], $block['file']));
    }

    // --- F-T26 -----------------------------------------------------------------------------------

    public function testExplainBlockExpectedBodyContainsWhitespaceOnlyLines(): void
    {
        $explainBlock = null;
        foreach (self::consoleBlocks() as $block) {
            if ($block['dollarLine'] === self::REQUIRED_DOLLAR_LINES[2]) {
                $explainBlock = $block;

                break;
            }
        }

        self::assertNotNull($explainBlock, 'The explain console block was not found.');

        self::assertMatchesRegularExpression(
            '/^[ ]+$/m',
            $explainBlock['expectedBody'],
            'The explain block\'s expected body lost its whitespace-only lines — an editor or formatter '
                . 'likely trimmed trailing whitespace that is part of the golden output (§4.8).',
        );
    }

    // --- helpers -----------------------------------------------------------------------------------

    /** @return array<string, string|bool|list<string>> */
    private static function parseDollarLine(string $dollarLine, string $fixtureRealPath): array
    {
        if (preg_match('/^\$ php bin\/php-modern-guidelines ([a-z][a-z0-9-]*)((?: [^ ]+)*)$/', $dollarLine, $m) !== 1) {
            self::fail(sprintf('"%s" does not match the pinned console $ line grammar.', $dollarLine));
        }
        $command = $m[1];
        $rest = trim($m[2]);
        $tokens = $rest === '' ? [] : explode(' ', $rest);

        $byNameOrAlias = self::commandsByNameOrAlias();
        self::assertArrayHasKey($command, $byNameOrAlias, sprintf('"%s" is not a real command.', $command));
        $nativeDefinition = $byNameOrAlias[$command]->getNativeDefinition();
        $argumentNames = array_keys($nativeDefinition->getArguments());
        $firstArgumentName = $argumentNames[0] ?? null;
        $nativeOptions = self::optionsOf($nativeDefinition);

        /** @var array<string, string|bool|list<string>> $input */
        $input = ['command' => $command];
        $positionalUsed = false;

        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                $withoutDashes = substr($token, 2);
                if (str_contains($withoutDashes, '=')) {
                    [$flag, $value] = explode('=', $withoutDashes, 2);
                    $value = str_replace('/path/to/app', $fixtureRealPath, $value);

                    if (isset($nativeOptions[$flag]) && $nativeOptions[$flag]->isArray()) {
                        /** @var list<string> $existing */
                        $existing = is_array($input['--' . $flag] ?? null) ? $input['--' . $flag] : [];
                        $existing[] = $value;
                        $input['--' . $flag] = $existing;
                    } else {
                        $input['--' . $flag] = $value;
                    }
                } else {
                    $input['--' . $withoutDashes] = true;
                }

                continue;
            }

            self::assertFalse($positionalUsed, sprintf('More than one positional token in: %s', $dollarLine));
            self::assertNotNull($firstArgumentName, sprintf('"%s" declares no argument to hold "%s".', $command, $token));

            $input[$firstArgumentName] = str_replace('/path/to/app', $fixtureRealPath, $token);
            $positionalUsed = true;
        }

        /** @var array<string, string|bool|list<string>> $result */
        $result = $input;

        return $result;
    }
}

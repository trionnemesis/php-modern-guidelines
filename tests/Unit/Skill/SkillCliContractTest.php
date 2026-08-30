<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Skill;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Diagnostics\DoctorRunner;
use ModernPhpGuidelines\Policy\WarningCatalogue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The truthfulness mechanism: every command, option, exit code, rule id and warning code the skill
 * file set asserts (in backticks) must exist in the real, live CLI, and reverse coverage must hold.
 * WORK-ORDER.md §4.7, rows F-T13 through F-T22, and F-T27.
 */
final class SkillCliContractTest extends TestCase
{
    private const SKILL_ROOT = __DIR__ . '/../../../skills';
    private const SKILL_MD = self::SKILL_ROOT . '/php-modern-guidelines/SKILL.md';
    private const CLI_CONTRACT = self::SKILL_ROOT . '/php-modern-guidelines/references/cli-contract.md';
    private const TWO_AXIS = self::SKILL_ROOT . '/php-modern-guidelines/references/two-axis-policy.md';
    private const SNIPPET = self::SKILL_ROOT . '/agents-md/SNIPPET.md';

    /** @var list<string> */
    private const FILE_SET = [self::SKILL_MD, self::CLI_CONTRACT, self::TWO_AXIS, self::SNIPPET];

    private const RULES_DIR = __DIR__ . '/../../../resources/rules';
    private const FIXTURE = __DIR__ . '/../../fixtures/projects/caret-8-2';

    /** First words of the non-`php-modern-guidelines` commands the skill file set is allowed to show. */
    private const FOREIGN_COMMAND_FIRST_WORDS = ['git', 'composer', 'curl', 'sha256sum', 'cp', 'chmod', 'mkdir'];

    /** Advanced/testing options exempt from reverse coverage (F-T21). */
    private const REVERSE_COVERAGE_ALLOW_LIST = ['rules-dir'];

    private const OPTION_CHECKED_COMMANDS = ['resolve', 'list-rules', 'explain', 'doctor'];

    // --- live surface --------------------------------------------------------------------------

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

    /** @return array<string, Command> keyed by every name AND alias, exactly $application->all()'s own-command slice */
    private static function ownCommandsByNameOrAlias(): array
    {
        /** @var array<string, Command> $own */
        $own = array_filter(
            self::application()->all(),
            static fn(Command $c): bool => str_starts_with($c::class, 'ModernPhpGuidelines\\Command\\'),
        );

        return $own;
    }

    /** @return list<string> the de-duplicated getName() set — five canonical command names */
    private static function ownCommandNames(): array
    {
        $own = self::ownCommandsByNameOrAlias();

        return array_values(array_unique(array_map(static fn(Command $c): string => self::requireName($c), $own)));
    }

    private static function requireName(Command $c): string
    {
        $name = $c->getName();
        self::assertNotNull($name, sprintf('%s has no name.', $c::class));

        return $name;
    }

    /** @return list<string> every alias of every own command */
    private static function ownAliases(): array
    {
        $aliases = [];
        foreach (array_values(self::ownCommandsByNameOrAlias()) as $command) {
            array_push($aliases, ...self::aliasesOf($command));
        }

        return array_values(array_unique($aliases));
    }

    /** @return list<string> */
    private static function aliasesOf(Command $c): array
    {
        /** @var list<string> $aliases */
        $aliases = $c->getAliases();

        return $aliases;
    }

    /** @return list<string> */
    private static function ownCommandsAndAliases(): array
    {
        return array_values(array_unique(array_merge(self::ownCommandNames(), self::ownAliases())));
    }

    /** @return array<string, InputOption> */
    private static function optionsOf(InputDefinition $definition): array
    {
        /** @var array<string, InputOption> $options */
        $options = $definition->getOptions();

        return $options;
    }

    /** @return list<string> the long option names declared on $definition */
    private static function longOptionNamesOf(InputDefinition $definition): array
    {
        return array_keys(self::optionsOf($definition));
    }

    /** @return list<string> every single-character shortcut $definition's options accept */
    private static function shortcutsOf(InputDefinition $definition): array
    {
        $shortcuts = [];
        foreach (self::optionsOf($definition) as $option) {
            array_push($shortcuts, ...self::shortcutPieces($option));
        }

        return array_values(array_unique($shortcuts));
    }

    /** @return list<string> long names of the application's global (merged) options */
    private static function globalLongOptions(): array
    {
        return self::longOptionNamesOf(self::application()->getDefinition());
    }

    /** @return list<string> every single-character shortcut any global option accepts */
    private static function globalShortcuts(): array
    {
        return self::shortcutsOf(self::application()->getDefinition());
    }

    /** @return list<string> */
    private static function shortcutPieces(InputOption $option): array
    {
        $shortcut = $option->getShortcut();
        if ($shortcut === null) {
            return [];
        }

        return array_values(array_filter(explode('|', $shortcut), static fn(string $s): bool => $s !== ''));
    }

    /** @return list<string> the union of every own command's native long option names */
    private static function unionNativeLongOptions(): array
    {
        $names = [];
        foreach (array_values(self::ownCommandsByNameOrAlias()) as $command) {
            array_push($names, ...self::longOptionNamesOf($command->getNativeDefinition()));
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> the union of every own command's native option shortcuts */
    private static function unionNativeShortcuts(): array
    {
        $shortcuts = [];
        foreach (array_values(self::ownCommandsByNameOrAlias()) as $command) {
            array_push($shortcuts, ...self::shortcutsOf($command->getNativeDefinition()));
        }

        return array_values(array_unique($shortcuts));
    }

    /** @return list<string> */
    private static function ruleIds(): array
    {
        $files = glob(self::RULES_DIR . '/*.json') ?: [];
        $ids = array_map(static fn(string $f): string => basename($f, '.json'), $files);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /** @return list<string> in the order emitted by a live `resolve --json` of the fixture */
    private static function resolveJsonKeysInOrder(): array
    {
        $tester = new ApplicationTester(self::application());
        $exitCode = $tester->run(
            ['command' => 'resolve', '--project-root' => self::FIXTURE, '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );
        self::assertSame(ExitCode::SUCCESS, $exitCode);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        return array_keys($decoded);
    }

    /** @return array<string, mixed> the full decoded `resolve --json` document for the fixture */
    private static function resolveJsonDecoded(): array
    {
        $tester = new ApplicationTester(self::application());
        $tester->run(
            ['command' => 'resolve', '--project-root' => self::FIXTURE, '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return list<string> every WarningCatalogue::CODE_* value */
    private static function warningCatalogueCodes(): array
    {
        $reflection = new \ReflectionClass(WarningCatalogue::class);
        $codes = [];
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'CODE_') && is_string($value)) {
                $codes[] = $value;
            }
        }

        return $codes;
    }

    /** @return list<string> the namespaces (segment before the first dot) of every warning code */
    private static function warningCodeNamespaces(): array
    {
        $namespaces = array_map(
            static fn(string $code): string => (string) (strstr($code, '.', true) ?: $code),
            self::warningCatalogueCodes(),
        );

        return array_values(array_unique($namespaces));
    }

    // --- extraction ------------------------------------------------------------------------------

    /**
     * @return array{fencedBlocks: list<array{info: string, lines: list<string>}>, inlineSpans: list<string>}
     */
    private static function extract(string $path): array
    {
        $contents = (string) file_get_contents($path);

        $fencedBlocks = [];
        $withoutFenced = preg_replace_callback(
            '/^```([a-zA-Z]*)\n(.*?)^```$/ms',
            static function (array $m) use (&$fencedBlocks): string {
                $body = $m[2];
                $lines = $body === '' ? [] : explode("\n", rtrim($body, "\n"));
                $fencedBlocks[] = ['info' => $m[1], 'lines' => $lines];

                return '';
            },
            $contents,
        );

        self::assertIsString($withoutFenced, sprintf('Fenced-block extraction failed for %s.', $path));

        preg_match_all('/`([^`\n]+)`/', $withoutFenced, $spanMatches);

        return ['fencedBlocks' => $fencedBlocks, 'inlineSpans' => $spanMatches[1]];
    }

    /** @return list<array{info: string, lines: list<string>, file: string}> every fenced block across the file set */
    private static function allFencedBlocks(): array
    {
        $blocks = [];
        foreach (self::FILE_SET as $path) {
            foreach (self::extract($path)['fencedBlocks'] as $block) {
                $blocks[] = $block + ['file' => $path];
            }
        }

        return $blocks;
    }

    /** @return list<string> every inline code span across the file set, outside fenced blocks */
    private static function allInlineSpans(): array
    {
        $spans = [];
        foreach (self::FILE_SET as $path) {
            array_push($spans, ...self::extract($path)['inlineSpans']);
        }

        return $spans;
    }

    /**
     * A "unit" (§4.7): one line of a fenced block, or one inline code span. A leading "$ " prompt is
     * stripped before classification.
     *
     * @return list<string>
     */
    private static function allUnits(): array
    {
        $units = [];
        foreach (self::allFencedBlocks() as $block) {
            array_push($units, ...$block['lines']);
        }
        array_push($units, ...self::allInlineSpans());

        return array_map(
            static fn(string $u): string => str_starts_with($u, '$ ') ? substr($u, 2) : $u,
            $units,
        );
    }

    /** The concatenated corpus (fenced bodies + inline spans) F-T13/F-T17/F-T22 tokenise. */
    private static function corpus(): string
    {
        return implode("\n", self::allUnits());
    }

    private static function isOptionChecked(string $unit): bool
    {
        if (!str_contains($unit, ' ') && str_starts_with($unit, '-')) {
            return true;
        }

        $firstWord = strtok($unit, ' ');
        if ($firstWord === false) {
            return false;
        }

        if (in_array($firstWord, ['php', 'php-modern-guidelines', 'bin/php-modern-guidelines', 'vendor/bin/php-modern-guidelines'], true)) {
            return true;
        }

        return in_array($firstWord, self::ownCommandsAndAliases(), true);
    }

    /** @return list<string> long-option tokens (without the leading "--") matched in $unit */
    private static function longOptionTokens(string $unit): array
    {
        preg_match_all('/(?<![A-Za-z0-9-])--([a-z][a-z0-9-]*)/', $unit, $m);

        return $m[1];
    }

    /** @return list<string> short-option tokens (single letters) matched in $unit */
    private static function shortOptionTokens(string $unit): array
    {
        preg_match_all('/(?<![A-Za-z0-9-])-([a-zA-Z])(?![A-Za-z0-9-])/', $unit, $m);

        return $m[1];
    }

    /**
     * The pinned tokeniser for F-T17 and F-T22 (WORK-ORDER.md §4.7). Never used for option scoping.
     *
     * @return list<string>
     */
    private static function tokens(string $corpus): array
    {
        $raw = preg_split('/[^A-Za-z0-9_.-]+/', $corpus, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];
        foreach ($raw as $token) {
            $trimmed = trim($token, '.-');
            if ($trimmed !== '') {
                $tokens[] = $trimmed;
            }
        }

        return $tokens;
    }

    // --- F-T13 -----------------------------------------------------------------------------------

    public function testEveryInvokedCommandIsOwnOrAlias(): void
    {
        $ownCommandsAndAliases = self::ownCommandsAndAliases();

        preg_match_all(
            '/^(?:\$ )?(?:php )?(?:(?:vendor\/)?bin\/)?php-modern-guidelines ([a-z][a-z0-9-]*)\b/m',
            self::corpus(),
            $matches,
        );

        self::assertNotEmpty($matches[1], 'No command invocation found in the skill file set.');

        foreach ($matches[1] as $command) {
            self::assertContains($command, $ownCommandsAndAliases, sprintf('"%s" is not a real command or alias.', $command));
        }
    }

    // --- F-T14 / F-T14a / F-T15 --------------------------------------------------------------------

    public function testOptionTokensAreRealInOptionCheckedUnitsAndForeignUnitsAreOnTheAllowList(): void
    {
        $longAllow = array_values(array_unique(array_merge(self::unionNativeLongOptions(), self::globalLongOptions())));
        $shortAllow = array_values(array_unique(array_merge(self::unionNativeShortcuts(), self::globalShortcuts())));

        foreach (self::allUnits() as $unit) {
            $longTokens = self::longOptionTokens($unit);
            $shortTokens = self::shortOptionTokens($unit);
            $hasOptionToken = $longTokens !== [] || $shortTokens !== [];

            if (self::isOptionChecked($unit)) {
                foreach ($longTokens as $token) {
                    self::assertContains($token, $longAllow, sprintf('Long option "--%s" in "%s" is not a real CLI option.', $token, $unit));
                }
                foreach ($shortTokens as $token) {
                    self::assertContains($token, $shortAllow, sprintf('Short option "-%s" in "%s" is not a real CLI shortcut.', $token, $unit));
                }

                continue;
            }

            if ($hasOptionToken) {
                $firstWord = (string) strtok($unit, ' ');
                self::assertContains(
                    $firstWord,
                    self::FOREIGN_COMMAND_FIRST_WORDS,
                    sprintf('Foreign unit "%s" carries an option token but its first word is not an allowed foreign command.', $unit),
                );
            }
        }
    }

    // --- F-T16 -----------------------------------------------------------------------------------

    public function testConsoleBlockDollarLineOptionsMatchThatBlocksOwnCommand(): void
    {
        $byNameOrAlias = self::ownCommandsByNameOrAlias();
        $globalLong = self::globalLongOptions();
        $globalShort = self::globalShortcuts();

        $consoleBlocks = array_values(array_filter(self::allFencedBlocks(), static fn(array $b): bool => $b['info'] === 'console'));
        self::assertNotEmpty($consoleBlocks, 'No console block found.');

        foreach ($consoleBlocks as $block) {
            $dollarLine = $block['lines'][0] ?? '';
            self::assertMatchesRegularExpression(
                '/^\$ php bin\/php-modern-guidelines ([a-z][a-z0-9-]*)((?: [^ ]+)*)$/',
                $dollarLine,
                sprintf('Console block $ line in %s does not match the pinned grammar: %s', $block['file'], $dollarLine),
            );

            if (preg_match('/^\$ php bin\/php-modern-guidelines ([a-z][a-z0-9-]*)/', $dollarLine, $m) !== 1) {
                self::fail(sprintf('"%s" does not match the pinned grammar.', $dollarLine));
            }
            $command = $m[1];

            self::assertArrayHasKey($command, $byNameOrAlias, sprintf('"%s" is not a real command.', $command));
            $native = $byNameOrAlias[$command]->getNativeDefinition();
            $nativeLong = self::longOptionNamesOf($native);
            $nativeShort = self::shortcutsOf($native);

            foreach (self::longOptionTokens($dollarLine) as $token) {
                self::assertTrue(
                    in_array($token, $nativeLong, true) || in_array($token, $globalLong, true),
                    sprintf('"--%s" is not declared by "%s".', $token, $command),
                );
            }
            foreach (self::shortOptionTokens($dollarLine) as $token) {
                self::assertTrue(
                    in_array($token, $nativeShort, true) || in_array($token, $globalShort, true),
                    sprintf('"-%s" is not declared by "%s".', $token, $command),
                );
            }
        }
    }

    // --- F-T17 -----------------------------------------------------------------------------------

    public function testEveryRuleIdLikeTokenIsARealRuleId(): void
    {
        $ruleIds = self::ruleIds();
        $found = false;

        foreach (self::tokens(self::corpus()) as $token) {
            if (preg_match('/^(language|core|extension)\.[a-z0-9_]+$/', $token) !== 1) {
                continue;
            }

            $found = true;
            self::assertContains($token, $ruleIds, sprintf('"%s" is not a rule id in resources/rules/.', $token));
        }

        self::assertTrue($found, 'No rule-id-shaped token found in the skill file set.');
    }

    // --- F-T18 -----------------------------------------------------------------------------------

    public function testExitCodeTableDigitsMatchTheExitCodeConstants(): void
    {
        $reflection = new \ReflectionClass(ExitCode::class);
        $expected = array_map(static function (mixed $v): string {
            if (!is_int($v)) {
                self::fail('ExitCode declares a non-int constant.');
            }

            return (string) $v;
        }, array_values($reflection->getConstants()));
        sort($expected, SORT_STRING);

        $lines = explode("\n", (string) file_get_contents(self::CLI_CONTRACT));
        $digits = [];
        foreach ($lines as $line) {
            if (preg_match('/^\| `(\d)` \| (.+) \|$/', $line, $m) === 1) {
                $digits[] = $m[1];
            }
        }

        self::assertNotEmpty($digits, 'No exit-code table row found.');
        $digits = array_values(array_unique($digits));
        sort($digits, SORT_STRING);

        self::assertSame($expected, $digits);
    }

    // --- F-T19 -----------------------------------------------------------------------------------

    public function testResolveJsonKeyListMatchesTheLivePolicyInOrder(): void
    {
        $contents = (string) file_get_contents(self::CLI_CONTRACT);
        $headingPos = strpos($contents, "### resolve --json keys\n");
        self::assertNotFalse($headingPos, 'Missing "### resolve --json keys" heading.');

        $afterHeading = explode("\n", substr($contents, $headingPos));
        array_shift($afterHeading); // drop the heading line itself

        $keys = [];
        foreach ($afterHeading as $line) {
            if (preg_match('/^- `([a-z_]+)`$/', $line, $m) !== 1) {
                if ($keys === []) {
                    continue; // allow a blank line between the heading and the list
                }

                break;
            }

            $keys[] = $m[1];
        }

        self::assertSame(self::resolveJsonKeysInOrder(), $keys);
    }

    // --- F-T20 -----------------------------------------------------------------------------------

    public function testReverseCoverageEveryOwnCommandIsDocumented(): void
    {
        $units = self::allUnits();

        foreach (self::ownCommandNames() as $command) {
            $documented = false;
            foreach ($units as $unit) {
                $firstWord = strtok($unit, ' ');
                if ($firstWord === $command) {
                    $documented = true;

                    break;
                }
            }

            self::assertTrue($documented, sprintf('Command "%s" does not appear anywhere in the skill file set.', $command));
        }
    }

    // --- F-T21 -----------------------------------------------------------------------------------

    public function testReverseCoverageEveryNativeOptionIsDocumented(): void
    {
        $byNameOrAlias = self::ownCommandsByNameOrAlias();
        $corpus = self::corpus();

        foreach (self::OPTION_CHECKED_COMMANDS as $command) {
            $native = $byNameOrAlias[$command]->getNativeDefinition();
            foreach (self::longOptionNamesOf($native) as $longName) {
                if (in_array($longName, self::REVERSE_COVERAGE_ALLOW_LIST, true)) {
                    continue;
                }

                self::assertStringContainsString(
                    '--' . $longName,
                    $corpus,
                    sprintf('Option "--%s" of "%s" does not appear anywhere in the skill file set.', $longName, $command),
                );
            }
        }
    }

    // --- F-T22 -----------------------------------------------------------------------------------

    public function testEveryCoarseShapedTokenClassifiesCleanlyAcrossTheFourTiers(): void
    {
        $ruleIds = self::ruleIds();
        $checkIds = DoctorRunner::CHECK_IDS;
        $policy = self::resolveJsonDecoded();
        $warningCodes = self::warningCatalogueCodes();
        $warningNamespaces = self::warningCodeNamespaces();

        $found = false;

        foreach (self::tokens(self::corpus()) as $token) {
            if (preg_match('/^[a-z]+\.[a-z_]+$/', $token) !== 1) {
                continue;
            }

            $found = true;

            // Tier 1: rule id.
            if (preg_match('/^(language|core|extension)\.[a-z0-9_]+$/', $token) === 1) {
                self::assertContains($token, $ruleIds, sprintf('"%s" looks like a rule id but is not one.', $token));

                continue;
            }

            // Tier 2: doctor check id.
            if (in_array($token, $checkIds, true)) {
                continue;
            }

            // Tier 3: policy-schema key path, computed from a live resolve --json. The first segment
            // must be a top-level policy key whose value is an associative object (not a list), and
            // the second segment one of that object's own keys.
            $segments = explode('.', $token, 2);
            $key = $segments[0];
            $subkey = $segments[1] ?? null;
            $value = $policy[$key] ?? null;
            if ($subkey !== null && is_array($value) && !array_is_list($value) && array_key_exists($subkey, $value)) {
                continue;
            }

            // Tier 4: warning code, only if the segment before the dot is a namespace WarningCatalogue
            // actually uses.
            if (in_array($key, $warningNamespaces, true)) {
                self::assertContains($token, $warningCodes, sprintf('"%s" is not a real WarningCatalogue code.', $token));

                continue;
            }

            // Tier 5: everything else matching the coarse shape (composer.json, require.php, ...) is
            // deliberately not contract-checked.
        }

        self::assertTrue($found, 'No coarse-shaped token found in the skill file set.');
    }

    // --- F-T27 -----------------------------------------------------------------------------------

    public function testNoWildcardOrEllipsisInAnyInlineCodeSpan(): void
    {
        foreach (self::allInlineSpans() as $span) {
            self::assertStringNotContainsString('*', $span, sprintf('Inline span "%s" contains a wildcard; write every code out in full (§4.2/F-T22).', $span));
            self::assertStringNotContainsString('…', $span, sprintf('Inline span "%s" contains an ellipsis.', $span));
            self::assertStringNotContainsString('...', $span, sprintf('Inline span "%s" contains an ellipsis.', $span));
        }
    }
}

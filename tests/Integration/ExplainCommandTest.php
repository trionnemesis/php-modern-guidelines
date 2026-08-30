<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Rule\Rule;
use ModernPhpGuidelines\Support\JsonPrinter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ExplainCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/projects';
    private const CLI_GOLDEN = __DIR__ . '/../fixtures/cli';

    public function testKnownRuleHumanOutputMatchesGolden(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'explain', 'rule-id' => 'language.property_hooks', '--project-root' => $fixtureRealPath],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('', $tester->getErrorOutput());

        $expected = $this->goldenContents(self::CLI_GOLDEN . '/explain-language.property_hooks.txt', $fixtureRealPath);
        self::assertSame($expected, $tester->getDisplay());
    }

    public function testKnownRuleJsonOutputMatchesGolden(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'explain', 'rule-id' => 'language.property_hooks', '--project-root' => $fixtureRealPath, '--json' => true],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);

        $expected = $this->goldenContents(self::CLI_GOLDEN . '/explain-language.property_hooks.json', $fixtureRealPath);
        self::assertSame($expected, $tester->getDisplay());
    }

    public function testUnknownRuleIdExitsThreeWithExactMessage(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'explain', 'rule-id' => 'nope.nope', '--project-root' => $this->realFixture('caret-8-2')],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::UNKNOWN_RULE, $exitCode);
        self::assertSame('', $tester->getDisplay());
        self::assertSame("Error: Unknown rule id \"nope.nope\".\n", $tester->getErrorOutput());
    }

    public function testCloseTypoSuggestsTheRealId(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'explain', 'rule-id' => 'language.property_hook', '--project-root' => $this->realFixture('caret-8-2')],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::UNKNOWN_RULE, $exitCode);
        self::assertStringContainsString('Did you mean "language.property_hooks"?', $tester->getErrorOutput());
    }

    public function testExplainJsonRuleRoundTripsThroughRuleFromArray(): void
    {
        $tester = $this->tester();
        $tester->run(
            ['command' => 'explain', 'rule-id' => 'language.property_hooks', '--project-root' => $this->realFixture('caret-8-2'), '--json' => true],
            ['decorated' => false],
        );

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['rule']);

        /** @var array<string, mixed> $ruleData */
        $ruleData = $decoded['rule'];
        $rule = Rule::fromArray($ruleData);

        // §5.3 claim 1: Rule::fromArray($rule->toArray())->toArray() equals $rule->toArray(), for the
        // rule reconstructed from the CLI's own --json output. Loose equality only: toArray() mints a
        // fresh \stdClass for package_constraints on every call, so assertSame()/=== on the arrays
        // themselves would fail even comparing one object's own two toArray() calls to each other.
        $roundTripped = Rule::fromArray($rule->toArray());
        self::assertEquals($rule->toArray(), $roundTripped->toArray());
        self::assertSame(JsonPrinter::encode($rule->toArray()), JsonPrinter::encode($roundTripped->toArray()));
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
}

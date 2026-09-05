<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\ListCommand as SymfonyListCommand;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ListCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/projects';
    private const CLI_GOLDEN = __DIR__ . '/../fixtures/cli';

    /**
     * @param array<array-key, mixed> $extraArgs
     */
    #[DataProvider('humanGoldenCases')]
    public function testHumanOutputMatchesGolden(array $extraArgs, string $golden): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $tester = $this->tester();
        $exitCode = $tester->run(
            array_merge(['command' => 'list-rules', '--project-root' => $fixtureRealPath], $extraArgs),
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('', $tester->getErrorOutput());

        $expected = $this->goldenContents(self::CLI_GOLDEN . '/list-rules-' . $golden . '.txt', $fixtureRealPath);
        self::assertSame($expected, $tester->getDisplay());
    }

    /**
     * The issue's REQUIRED integration demonstration (ISSUE-3.md PR D acceptance / WORK-ORDER.md §5.5):
     * a broad range forbids too-new syntax while still surfacing deprecations from newer allowed minors,
     * and single-target narrows the same catalogue differently — proof that the two axes never collapse
     * (ADR-004). Both arms pass --all so the two runs share the identical 40-rule id set and the
     * inequality can only come from differing statuses.
     */
    public function testBroadRangeForbidsTooNewSyntaxWhileStillSurfacingNewerDeprecations(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $arm1 = $this->runJson($fixtureRealPath, ['--all' => true]);
        $policy1 = self::obj($arm1['policy']);
        self::assertSame('8.2', self::str($policy1['feature_ceiling']));
        self::assertSame('8.5', self::str($policy1['lifecycle_ceiling']));

        $rules1 = self::objList($arm1['rules']);
        self::assertCount(40, $rules1);

        $byId1 = $this->indexById($rules1);
        self::assertSame('forbidden_above_feature_ceiling', self::ruleStatus($byId1['language.property_hooks']));
        self::assertFalse(self::usableAcrossRange($byId1['language.property_hooks']));
        self::assertSame('forbidden_above_feature_ceiling', self::ruleStatus($byId1['language.pipe_operator']));
        self::assertSame('deprecated_in_range', self::ruleStatus($byId1['extension.curl_close']));
        self::assertSame(['8.5'], self::affectedMinors($byId1['extension.curl_close']));
        self::assertSame('removed_in_range', self::ruleStatus($byId1['extension.imap_unbundled']));
        self::assertSame(['8.4', '8.5'], self::affectedMinors($byId1['extension.imap_unbundled']));
        self::assertSame('applicable', self::ruleStatus($byId1['language.readonly_classes']));

        $arm2 = $this->runJson($fixtureRealPath, ['--mode' => 'single-target', '--all' => true]);
        $policy2 = self::obj($arm2['policy']);
        self::assertSame('8.2', self::str($policy2['feature_ceiling']));
        self::assertSame('8.2', self::str($policy2['lifecycle_ceiling']));

        $rules2 = self::objList($arm2['rules']);
        self::assertCount(40, $rules2);

        $byId2 = $this->indexById($rules2);
        self::assertSame('not_in_range', self::ruleStatus($byId2['language.property_hooks']));
        self::assertSame('not_in_range', self::ruleStatus($byId2['language.pipe_operator']));
        self::assertSame('applicable', self::ruleStatus($byId2['extension.curl_close']));
        self::assertSame('applicable', self::ruleStatus($byId2['extension.imap_unbundled']));
        self::assertSame('applicable', self::ruleStatus($byId2['language.readonly_classes']));

        $statusMap1 = [];
        foreach ($byId1 as $id => $rule) {
            $statusMap1[$id] = self::ruleStatus($rule);
        }

        $statusMap2 = [];
        foreach ($byId2 as $id => $rule) {
            $statusMap2[$id] = self::ruleStatus($rule);
        }

        ksort($statusMap1);
        ksort($statusMap2);

        self::assertSame(array_keys($statusMap1), array_keys($statusMap2));
        self::assertNotEquals($statusMap1, $statusMap2, 'ADR-004: feature and lifecycle ceilings must not collapse to one effective version.');
    }

    public function testNotInRangeRulesAreHiddenByDefaultAndShownWithAll(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $default = $this->runJson($fixtureRealPath, ['--mode' => 'single-target']);
        $all = $this->runJson($fixtureRealPath, ['--mode' => 'single-target', '--all' => true]);

        self::assertSame(40, $default['total']);
        self::assertSame(40, $all['total']);

        $defaultRules = self::objList($default['rules']);
        $allRules = self::objList($all['rules']);

        self::assertCount(25, $defaultRules);
        self::assertCount(40, $allRules);

        foreach ($defaultRules as $rule) {
            self::assertNotSame('not_in_range', self::ruleStatus($rule));
        }

        $notInRangeInAll = array_values(array_filter(
            $allRules,
            static fn(array $r): bool => self::ruleStatus($r) === 'not_in_range',
        ));
        self::assertCount(15, $notInRangeInAll);

        $defaultIds = self::ids($defaultRules);
        $allIds = self::ids($allRules);
        self::assertCount(0, array_diff($defaultIds, $allIds));
        self::assertNotSame($defaultIds, $allIds);
    }

    public function testMinorShapeErrorExitsTwo(): void
    {
        foreach (['8', '8.4.1', 'latest', '^8.4'] as $badMinor) {
            $tester = $this->tester();
            $exitCode = $tester->run(
                ['command' => 'list-rules', '--project-root' => $this->realFixture('caret-8-2'), '--minor' => $badMinor],
                ['capture_stderr_separately' => true, 'decorated' => false],
            );

            self::assertSame(ExitCode::INVALID_INPUT, $exitCode, "for --minor=$badMinor");
            self::assertStringContainsString('must be an "X.Y" PHP minor', $tester->getErrorOutput());
        }
    }

    public function testMinorMembershipErrorExitsTwo(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'list-rules', '--project-root' => $this->realFixture('caret-8-2'), '--minor' => '9.9'],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertStringContainsString('is not in this project\'s allowed minors', $tester->getErrorOutput());
    }

    public function testUnknownKindValueExitsTwoWithAcceptedList(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'list-rules', '--project-root' => $this->realFixture('caret-8-2'), '--kind' => ['featrue']],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::INVALID_INPUT, $exitCode);
        self::assertSame(
            "Error: Unknown --kind value \"featrue\". Expected one of: feature, modern_preference, deprecated, removed, compatibility_guard, behavior_change.\n",
            $tester->getErrorOutput(),
        );
    }

    public function testRepeatedFlagsInReversedOrderProduceIdenticalJson(): void
    {
        $fixtureRealPath = $this->realFixture('caret-8-2');

        $forward = $this->runJson($fixtureRealPath, ['--kind' => ['deprecated', 'removed']]);
        $reversed = $this->runJson($fixtureRealPath, ['--kind' => ['removed', 'deprecated']]);

        self::assertSame($forward, $reversed);

        $filters = self::obj($forward['filters']);
        self::assertSame(['deprecated', 'removed'], self::strList($filters['kind']));
    }

    public function testRulesDirPointedAtInvalidFixtureExitsFive(): void
    {
        $invalidDir = __DIR__ . '/../fixtures/rules/invalid/missing-required';

        $tester = $this->tester();
        $exitCode = $tester->run(
            [
                'command' => 'list-rules',
                '--project-root' => $this->realFixture('caret-8-2'),
                '--rules-dir' => $invalidDir,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::RULE_DATA_INVALID, $exitCode);
        self::assertSame('', $tester->getDisplay());
    }

    public function testNoCommandNamedListIsRegisteredAndSymfonyBuiltinListStillWorks(): void
    {
        $application = ApplicationFactory::create();

        self::assertInstanceOf(SymfonyListCommand::class, $application->get('list'));

        $tester = $this->tester();
        $exitCode = $tester->run(['command' => 'list'], ['decorated' => false]);

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('list-rules', $tester->getDisplay());
    }

    public function testListRulesAliasRulesWorks(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            ['command' => 'rules', '--project-root' => $this->realFixture('caret-8-2')],
            ['decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('Rules: 40 of 40 shown', $tester->getDisplay());
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function humanGoldenCases(): iterable
    {
        yield 'default' => [[], 'caret-8-2'];
        yield '--all' => [['--all' => true], 'caret-8-2--all'];
        yield '--mode=single-target' => [['--mode' => 'single-target'], 'caret-8-2--mode-single-target'];
        yield '--mode=single-target --all' => [['--mode' => 'single-target', '--all' => true], 'caret-8-2--mode-single-target--all'];
        yield '--kind=deprecated' => [['--kind' => ['deprecated']], 'caret-8-2--kind-deprecated'];
        yield '--category=extension' => [['--category' => ['extension']], 'caret-8-2--category-extension'];
        yield '--status=applicable' => [['--status' => ['applicable']], 'caret-8-2--status-applicable'];
        yield '--extension=curl' => [['--extension' => 'curl'], 'caret-8-2--extension-curl'];
        yield '--minor=8.5' => [['--minor' => '8.5'], 'caret-8-2--minor-8-5'];
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

    /**
     * @param  array<array-key, mixed> $extraArgs
     * @return array<array-key, mixed>
     */
    private function runJson(string $projectRoot, array $extraArgs): array
    {
        $tester = $this->tester();
        $exitCode = $tester->run(
            array_merge(['command' => 'list-rules', '--project-root' => $projectRoot, '--json' => true], $extraArgs),
            ['capture_stderr_separately' => true, 'decorated' => false],
        );

        self::assertSame(ExitCode::SUCCESS, $exitCode, $tester->getErrorOutput());

        return self::obj(json_decode($tester->getDisplay(), true));
    }

    /**
     * @param  list<array<array-key, mixed>> $rules
     * @return array<string, array<array-key, mixed>>
     */
    private function indexById(array $rules): array
    {
        $byId = [];
        foreach ($rules as $rule) {
            $byId[self::str($rule['id'])] = $rule;
        }

        return $byId;
    }

    /** @param array<array-key, mixed> $rule */
    private static function ruleStatus(array $rule): string
    {
        return self::str(self::obj($rule['applicability'])['status']);
    }

    /** @param array<array-key, mixed> $rule */
    private static function usableAcrossRange(array $rule): bool
    {
        $value = self::obj($rule['applicability'])['usable_across_range'];
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  array<array-key, mixed> $rule
     * @return list<string>
     */
    private static function affectedMinors(array $rule): array
    {
        return self::strList(self::obj($rule['applicability'])['affected_minors']);
    }

    /**
     * @param  list<array<array-key, mixed>> $rules
     * @return list<string>
     */
    private static function ids(array $rules): array
    {
        return array_map(static fn(array $rule): string => self::str($rule['id']), $rules);
    }

    /** @return array<array-key, mixed> */
    private static function obj(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /** @return list<array<array-key, mixed>> */
    private static function objList(mixed $value): array
    {
        self::assertIsArray($value);

        $list = [];
        foreach ($value as $item) {
            $list[] = self::obj($item);
        }

        return $list;
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return list<string> */
    private static function strList(mixed $value): array
    {
        self::assertIsArray($value);

        $list = [];
        foreach ($value as $item) {
            $list[] = self::str($item);
        }

        return $list;
    }
}

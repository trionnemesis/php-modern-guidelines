<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Rule\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ZeroNegativePowerTest extends TestCase
{
    private const ID = 'core.zero_negative_power';

    /** @return iterable<string, array{string, string, string}> */
    public static function policies(): iterable
    {
        yield '8.2' => ['8.2', 'applicable', 'not_in_range'];
        yield '8.3' => ['8.3', 'applicable', 'not_in_range'];
        yield '8.4' => ['8.4', 'deprecated_across_range', 'applicable'];
        yield '8.5' => ['8.5', 'deprecated_across_range', 'applicable'];
        yield '8.2-8.5' => ['>=8.2 <8.6', 'deprecated_in_range', 'forbidden_above_feature_ceiling'];
        yield '8.4-8.5' => ['>=8.4 <8.6', 'deprecated_across_range', 'applicable'];
    }

    #[DataProvider('policies')]
    public function testDeprecationAndReplacementHaveSeparatePolicyContracts(string $policy, string $status, string $featureStatus): void
    {
        $output = $this->json(['command' => 'explain', 'rule-id' => self::ID, '--php' => $policy]);
        self::assertIsArray($output['applicability']);
        self::assertSame($status, $output['applicability']['status']);
        $rule = $this->rule($output);
        self::assertSame('deprecated', $rule->kind->value);
        self::assertNull($rule->introducedIn);
        self::assertSame('8.4', $rule->deprecatedIn);
        self::assertNull($rule->removedIn);
        self::assertSame([], $rule->verification->phpcompatibility);

        $feature = $this->json(['command' => 'explain', 'rule-id' => 'core.fpow', '--php' => $policy]);
        self::assertIsArray($feature['applicability']);
        self::assertSame($featureStatus, $feature['applicability']['status']);
        $list = $this->json(['command' => 'list-rules', '--kind' => ['deprecated'], '--php' => $policy]);
        self::assertIsArray($list['rules']);
        self::assertContains(self::ID, array_column($list['rules'], 'id'));
        self::assertNotContains('core.fpow', array_column($list['rules'], 'id'));
        $human = $this->runCommand(['command' => 'explain', 'rule-id' => self::ID, '--php' => $policy]);
        self::assertStringContainsString($status, $human);
        self::assertStringContainsString($rule->guideline, (string) preg_replace('/\s+/', ' ', $human));
    }

    public function testDeliveredExamplesExposeTheNoticeAndTheirExplicitBehaviorChoices(): void
    {
        $rule = $this->rule($this->json(['command' => 'explain', 'rule-id' => self::ID, '--php' => '8.4']));
        $notices = [];
        set_error_handler(static function (int $severity) use (&$notices): bool {
            $notices[] = $severity;

            return true;
        });
        try {
            foreach ($rule->examples as $example) {
                self::assertNotNull($example->before);
                foreach ([0, 0.0, -0.0] as $base) {
                    $notices = [];
                    $result = $this->execute(implode("\n", $example->before), $base, -1);
                    self::assertIsFloat($result);
                    self::assertTrue(is_infinite($result));
                    self::assertSame(PHP_VERSION_ID >= 80400 ? [E_DEPRECATED] : [], $notices);
                }
            }

            self::assertNotNull($rule->examples[0]->after);
            $guard = implode("\n", $rule->examples[0]->after);
            foreach ([0, 0.0, -0.0] as $base) {
                $notices = [];
                try {
                    $this->execute($guard, $base, -1);
                    self::fail('The delivered guard must reject the invalid domain.');
                } catch (\DomainException) {
                    self::assertSame([], $notices);
                }
            }
            foreach ([[2, 10, 1024], [2, -1, 0.5], [0, 2, 0], [0, 0, 1], [-2, 3, -8]] as [$base, $exponent, $expected]) {
                $notices = [];
                self::assertSame($expected, $this->execute($guard, $base, $exponent));
                self::assertSame([], $notices);
            }

            if (PHP_VERSION_ID >= 80400) {
                self::assertNotNull($rule->examples[1]->after);
                $floatPower = implode("\n", $rule->examples[1]->after);
                $notices = [];
                $result = $this->execute($floatPower, 0, -1);
                self::assertSame(INF, $result);
                self::assertSame(1024.0, $this->execute($floatPower, 2, 10));
                self::assertSame([], $notices);
            }
        } finally {
            restore_error_handler();
        }
    }

    private function execute(string $code, int|float $base, int|float $exponent): mixed
    {
        return eval($code . "\nreturn \$result;");
    }

    /** @param array<string, mixed> $args */
    private function runCommand(array $args): string
    {
        $app = ApplicationFactory::create();
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        self::assertSame(0, $tester->run($args, ['capture_stderr_separately' => true, 'decorated' => false]));
        self::assertSame('', $tester->getErrorOutput());

        return $tester->getDisplay();
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function json(array $args): array
    {
        /** @var array<string, mixed> $result */
        $result = json_decode($this->runCommand($args + ['--json' => true]), true, 512, JSON_THROW_ON_ERROR);

        return $result;
    }

    /** @param array<string, mixed> $output */
    private function rule(array $output): Rule
    {
        self::assertIsArray($output['rule']);
        /** @var array<string, mixed> $data */
        $data = $output['rule'];

        return Rule::fromArray($data);
    }
}

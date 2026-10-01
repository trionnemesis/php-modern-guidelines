<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Rule\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class UnserializeUppercaseSTest extends TestCase
{
    private const ID = 'core.unserialize_uppercase_s';

    /** @return iterable<string, array{string, string}> */
    public static function policies(): iterable
    {
        yield '8.2' => ['8.2', 'applicable'];
        yield '8.3' => ['8.3', 'applicable'];
        yield '8.4' => ['8.4', 'deprecated_across_range'];
        yield '8.5' => ['8.5', 'deprecated_across_range'];
        yield '8.2-8.5' => ['>=8.2 <8.6', 'deprecated_in_range'];
        yield '8.4-8.5' => ['>=8.4 <8.6', 'deprecated_across_range'];
    }

    #[DataProvider('policies')]
    public function testLifecycleIsQueryableWithoutInventingAMapping(string $policy, string $status): void
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

        $list = $this->json(['command' => 'list-rules', '--kind' => ['deprecated'], '--php' => $policy]);
        self::assertIsArray($list['rules']);
        self::assertContains(self::ID, array_column($list['rules'], 'id'));
        $features = $this->json(['command' => 'list-rules', '--kind' => ['feature'], '--php' => $policy]);
        self::assertIsArray($features['rules']);
        self::assertNotContains(self::ID, array_column($features['rules'], 'id'));
        $human = $this->runCommand(['command' => 'explain', 'rule-id' => self::ID, '--php' => $policy]);
        self::assertStringContainsString($status, $human);
        self::assertStringContainsString($rule->guideline, (string) preg_replace('/\s+/', ' ', $human));
    }

    public function testDeliveredProducerExamplePreservesBytesAndAvoidsTheDeprecatedFormat(): void
    {
        $rule = $this->rule($this->json(['command' => 'explain', 'rule-id' => self::ID, '--php' => '8.4']));
        self::assertNotNull($rule->examples[0]->before);
        self::assertNotNull($rule->examples[0]->after);
        $before = implode("\n", $rule->examples[0]->before);
        $after = implode("\n", $rule->examples[0]->after);
        $notices = [];
        set_error_handler(static function (int $severity) use (&$notices): bool {
            $notices[] = $severity;

            return true;
        });
        try {
            self::assertSame('a', $this->execute($before));
            self::assertSame(PHP_VERSION_ID >= 80400 ? [E_DEPRECATED] : [], $notices);
            $notices = [];
            self::assertSame('a', $this->execute($after));
            self::assertSame([], $notices);
            $encodeString = eval($after . "\nreturn \$encodeString;");
            self::assertInstanceOf(\Closure::class, $encodeString);
            foreach (['a', '', "\0", 'é', '"', '\\', '\\61'] as $value) {
                $notices = [];
                $payload = $encodeString($value);
                self::assertIsString($payload);
                self::assertSame($value, unserialize($payload, ['allowed_classes' => false]));
                self::assertSame([], $notices);
            }
            // A length-correct lowercase rewrite still changes the value of an escaped S payload.
            $notices = [];
            self::assertSame('\\61', unserialize('s:3:"\\61";', ['allowed_classes' => false]));
            self::assertSame([], $notices);
            $notices = [];
            self::assertSame('a', unserialize('S:1:"a";', ['allowed_classes' => false]));
            self::assertSame(PHP_VERSION_ID >= 80400 ? [E_DEPRECATED] : [], $notices);
        } finally {
            restore_error_handler();
        }
    }

    private function execute(string $code): mixed
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

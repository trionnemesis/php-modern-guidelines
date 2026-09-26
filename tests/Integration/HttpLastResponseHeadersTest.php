<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Rule\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class HttpLastResponseHeadersTest extends TestCase
{
    private const ID = 'core.http_last_response_headers';

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function policies(): iterable
    {
        yield 'single 8.2' => [['--php' => '8.2'], 'not_in_range'];
        yield 'single 8.3' => [['--php' => '8.3'], 'not_in_range'];
        yield 'range 8.2-8.5' => [['--php' => '>=8.2 <8.6'], 'forbidden_above_feature_ceiling'];
        yield 'single 8.4' => [['--php' => '8.4'], 'applicable'];
        yield 'range 8.4-8.5' => [['--php' => '>=8.4 <8.6'], 'applicable'];
        yield 'single 8.5' => [['--php' => '8.5'], 'applicable'];
    }

    /** @param array<string, string> $policy */
    #[DataProvider('policies')]
    public function testFeatureQueryAndExplainRespectThePolicy(array $policy, string $expected): void
    {
        $explain = $this->json(['command' => 'explain', 'rule-id' => self::ID] + $policy);
        self::assertIsArray($explain['applicability']);
        self::assertSame($expected, $explain['applicability']['status']);
        self::assertSame($expected === 'applicable', $explain['applicability']['usable_across_range']);
        $rule = $this->rule($explain);
        self::assertSame('core', $rule->category->value);
        self::assertSame('feature', $rule->kind->value);
        self::assertSame('8.4', $rule->introducedIn);
        self::assertNull($rule->deprecatedIn);
        self::assertNull($rule->removedIn);
        self::assertSame([], $rule->verification->phpcompatibility);

        foreach ([false, true] as $all) {
            $list = $this->json(['command' => 'list-rules', '--kind' => ['feature'], '--all' => $all] + $policy);
            self::assertIsArray($list['rules']);
            $byId = [];
            foreach ($list['rules'] as $row) {
                self::assertIsArray($row);
                self::assertIsString($row['id']);
                $byId[$row['id']] = $row;
            }
            self::assertArrayNotHasKey('core.http_response_header', $byId);
            if ($all || $expected !== 'not_in_range') {
                self::assertArrayHasKey(self::ID, $byId);
                self::assertIsArray($byId[self::ID]['applicability']);
                self::assertSame($expected, $byId[self::ID]['applicability']['status']);
            } else {
                self::assertArrayNotHasKey(self::ID, $byId);
            }
        }

        $human = $this->runCommand(['command' => 'explain', 'rule-id' => self::ID] + $policy);
        self::assertStringContainsString($expected, $human->getDisplay());
        self::assertStringContainsString('http_get_last_response_headers()', $human->getDisplay());
        self::assertStringContainsString('http_clear_last_response_headers()', $human->getDisplay());
    }

    public function testOldVariableKeepsItsSeparateDeprecationContract(): void
    {
        foreach (['8.4' => 'applicable', '8.5' => 'deprecated_across_range'] as $minor => $status) {
            $output = $this->json(['command' => 'explain', 'rule-id' => 'core.http_response_header', '--php' => $minor]);
            $rule = $this->rule($output);
            self::assertSame('deprecated', $rule->kind->value);
            self::assertNull($rule->introducedIn);
            self::assertSame('8.5', $rule->deprecatedIn);
            self::assertIsArray($output['applicability']);
            self::assertSame($status, $output['applicability']['status']);
        }
    }

    public function testDeliveredExampleCapturesThenClearsRealHttpHeaders(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('The delivered example explicitly requires PHP 8.4+.');
        }
        if (!function_exists('http_get_last_response_headers') || !function_exists('http_clear_last_response_headers')) {
            self::fail('PHP 8.4+ must provide both HTTP response-header helpers.');
        }
        $rule = $this->rule($this->json(['command' => 'explain', 'rule-id' => self::ID, '--php' => '8.4']));
        self::assertCount(1, $rule->examples);
        self::assertNotNull($rule->examples[0]->after);
        $code = implode("\n", $rule->examples[0]->after);

        $server = proc_open(
            [PHP_BINARY, __DIR__ . '/../fixtures/http/header-server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        try {
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            $address = fgets($pipes[1]);
            self::assertIsString($address, 'Loopback fixture must announce its address.');
            $url = 'http://' . trim($address) . '/';

            // Seed an earlier response, then execute the actual shipped example, not a copied helper.
            self::assertSame('fixture-body', file_get_contents($url));
            $seedHeaders = http_get_last_response_headers();
            self::assertIsArray($seedHeaders);
            self::assertContains('X-Request: 0', $seedHeaders);
            $capture = static function (string $url, string $code): mixed {
                return eval($code . "\nreturn [\$body, \$headers];");
            };
            $captured = $capture($url, $code);
            self::assertIsArray($captured);
            self::assertCount(2, $captured);
            [$body, $headers] = $captured;
            self::assertSame('fixture-body', $body);
            self::assertIsArray($headers);
            self::assertContains('HTTP/1.1 200 OK', $headers);
            self::assertContains('X-Request: 1', $headers);
            self::assertContains('X-Feature-Fixture: captured', $headers);
            self::assertNull(http_get_last_response_headers());
            // Clearing the shared state must not discard the request's captured array.
            self::assertContains('X-Feature-Fixture: captured', $headers);

            // The finally block must also clear when a warning becomes an exception.
            set_error_handler(static function (int $severity, string $message): never {
                throw new \RuntimeException($message);
            });
            try {
                $capture('missing-header-fixture-scheme://unavailable', $code);
                self::fail('The failed request must raise the controlled exception.');
            } catch (\RuntimeException) {
                self::assertNull(http_get_last_response_headers());
            } finally {
                restore_error_handler();
            }
        } finally {
            http_clear_last_response_headers();
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($server);
            proc_close($server);
        }
    }

    /** @param array<string, mixed> $args */
    private function runCommand(array $args): ApplicationTester
    {
        $app = ApplicationFactory::create();
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);
        $tester = new ApplicationTester($app);
        self::assertSame(0, $tester->run($args, ['capture_stderr_separately' => true, 'decorated' => false]));
        self::assertSame('', $tester->getErrorOutput());

        return $tester;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function json(array $args): array
    {
        /** @var array<string, mixed> $result */
        $result = json_decode($this->runCommand($args + ['--json' => true])->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

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

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Policy;

use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\PolicyResolver;
use ModernPhpGuidelines\Policy\ResolutionMode;
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Support\JsonPrinter;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicySchemaValidatorTest extends TestCase
{
    // tests/Unit/Policy/ -> tests/Unit/ -> tests/ -> package root
    private const SCHEMA = __DIR__ . '/../../../schemas/policy.schema.json';

    private const FIXTURES = __DIR__ . '/../../fixtures/projects';
    private const GOLDEN = __DIR__ . '/../../fixtures/policy';

    #[DataProvider('cases')]
    public function testEveryCaseValidatesAgainstThePolicySchema(
        string $fixture,
        ResolutionMode $mode,
        ?string $php,
        ?string $golden,
    ): void {
        $resolver = new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator());
        $fixtureDir = self::FIXTURES . '/' . $fixture;
        $fixtureRealPath = realpath($fixtureDir);
        self::assertIsString($fixtureRealPath, sprintf('Fixture directory %s does not exist.', $fixtureDir));

        $policy = $resolver->resolve(new PolicyRequest($fixtureDir, $mode, $php));

        $this->assertValidatesAgainstSchema($policy);

        if ($golden === null) {
            // Case W (runtime-observed): structural assertions only, no golden file — its output
            // legitimately differs across the 8.2-8.5 CI matrix.
            self::assertSame([PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION], $policy->allowedMinors);
            self::assertSame(
                PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
                $policy->observedRuntime,
            );

            return;
        }

        $goldenPath = self::GOLDEN . '/' . $golden . '.json';
        $goldenContents = file_get_contents($goldenPath);
        self::assertIsString($goldenContents, sprintf('Could not read golden file %s.', $goldenPath));

        $expected = str_replace('@PROJECT_ROOT@', $fixtureRealPath, $goldenContents);
        $actual = JsonPrinter::encode($policy->toArray()) . "\n";

        self::assertSame($expected, $actual, sprintf('Golden mismatch for %s.', $golden));
    }

    private function assertValidatesAgainstSchema(ResolvedPolicy $policy): void
    {
        $schemaJson = file_get_contents(self::SCHEMA);
        self::assertIsString($schemaJson);
        /** @var mixed $decodedSchema */
        $decodedSchema = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($decodedSchema);

        $validator = new Validator();
        $validator->setMaxErrors(100);

        $data = Helper::toJSON($policy->toArray());
        $result = $validator->validate($data, $decodedSchema);

        $error = $result->error();
        if ($error !== null) {
            $formatted = (new ErrorFormatter())->format($error, true);
            $lines = [];
            foreach ($formatted as $pointer => $messages) {
                if (!is_array($messages)) {
                    $messages = [$messages];
                }
                foreach ($messages as $message) {
                    $lines[] = sprintf('%s: %s', (string) $pointer, is_string($message) ? $message : (string) json_encode($message));
                }
            }
            sort($lines, SORT_STRING);

            self::fail(implode("\n", $lines));
        }

        self::assertTrue($result->isValid());
    }

    /** @return iterable<string, array{string, ResolutionMode, string|null, string|null}> */
    public static function cases(): iterable
    {
        // [fixture, mode, phpOverride, golden-name-or-null]
        yield 'A caret-8-2' => ['caret-8-2', ResolutionMode::RangeSafe, null, 'caret-8-2'];
        yield 'B caret-8-4' => ['caret-8-4', ResolutionMode::RangeSafe, null, 'caret-8-4'];
        yield 'C tilde-patch' => ['tilde-patch', ResolutionMode::RangeSafe, null, 'tilde-patch'];
        yield 'D tilde-minor' => ['tilde-minor', ResolutionMode::RangeSafe, null, 'tilde-minor'];
        yield 'E comparison-range' => ['comparison-range', ResolutionMode::RangeSafe, null, 'comparison-range'];
        yield 'F or-constraint' => ['or-constraint', ResolutionMode::RangeSafe, null, 'or-constraint'];
        yield 'G exact-version' => ['exact-version', ResolutionMode::RangeSafe, null, 'exact-version'];
        yield 'H open-upper-unbounded' => ['open-upper-unbounded', ResolutionMode::RangeSafe, null, 'open-upper-unbounded'];
        yield 'I below-known-min' => ['below-known-min', ResolutionMode::RangeSafe, null, 'below-known-min'];
        yield 'L no-php-constraint' => ['no-php-constraint', ResolutionMode::RangeSafe, null, 'no-php-constraint'];
        yield 'M no-composer-json' => ['no-composer-json', ResolutionMode::RangeSafe, null, 'no-composer-json'];
        yield 'N platform-override' => ['platform-override', ResolutionMode::RangeSafe, null, 'platform-override'];
        yield 'O platform-override-conflict' => ['platform-override-conflict', ResolutionMode::RangeSafe, null, 'platform-override-conflict'];
        yield 'P lock-platform-override' => ['lock-platform-override', ResolutionMode::RangeSafe, null, 'lock-platform-override'];
        yield 'Q or-hole' => ['or-hole', ResolutionMode::RangeSafe, null, 'or-hole'];
        yield 'R patch-exclusion' => ['patch-exclusion', ResolutionMode::RangeSafe, null, 'patch-exclusion'];
        yield 'S caret-8-2 --php 8.4' => ['caret-8-2', ResolutionMode::RangeSafe, '8.4', 'caret-8-2--php-8-4'];
        yield 'U caret-8-2 --mode=single-target' => ['caret-8-2', ResolutionMode::SingleTarget, null, 'caret-8-2--mode-single-target'];
        yield 'V tilde-patch --mode=single-target' => ['tilde-patch', ResolutionMode::SingleTarget, null, 'tilde-patch--mode-single-target'];
        yield 'X conflict-php' => ['conflict-php', ResolutionMode::RangeSafe, null, 'conflict-php'];
        yield 'Y lock-platform-override-conflict' => ['lock-platform-override-conflict', ResolutionMode::RangeSafe, null, 'lock-platform-override-conflict'];
        yield 'Z1 platform-disabled' => ['platform-disabled', ResolutionMode::RangeSafe, null, 'platform-disabled'];
        yield 'Z2 lock-mismatch' => ['lock-mismatch', ResolutionMode::RangeSafe, null, 'lock-mismatch'];
        yield 'W caret-8-2 --mode=runtime-observed' => ['caret-8-2', ResolutionMode::RuntimeObserved, null, null];
    }
}

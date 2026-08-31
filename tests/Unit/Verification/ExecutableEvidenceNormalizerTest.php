<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification;

use ModernPhpGuidelines\Verification\ExecutableEvidenceNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExecutableEvidenceNormalizerTest extends TestCase
{
    #[DataProvider('executableCases')]
    public function testNormalizesMachineSpecificExecutablePaths(
        string $selected,
        string $projectRoot,
        string $expected,
    ): void {
        self::assertSame($expected, ExecutableEvidenceNormalizer::normalize($selected, $projectRoot));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function executableCases(): iterable
    {
        yield 'PATH name remains exact' => ['phpcs', '/workspace/project', 'phpcs'];
        yield 'relative project path remains relative' => [
            './vendor/bin/phpcs',
            '/workspace/project',
            'vendor/bin/phpcs',
        ];
        yield 'absolute project path becomes relative' => [
            '/workspace/project/vendor/bin/phpcs',
            '/workspace/project',
            'vendor/bin/phpcs',
        ];
        yield 'outside POSIX path keeps only identity' => [
            '/opt/phpcs/bin/phpcs',
            '/workspace/project',
            '<external>/phpcs',
        ];
        yield 'outside traversal keeps only identity' => [
            '../tools/phpcs',
            '/workspace/project',
            '<external>/phpcs',
        ];
        yield 'Windows project path becomes relative' => [
            'c:\\work\\app\\vendor\\bin\\phpcs.bat',
            'C:\\Work\\App',
            'vendor/bin/phpcs.bat',
        ];
        yield 'outside UNC path keeps only identity' => [
            '\\\\server\\share\\tools\\phpcs.exe',
            'C:\\Work\\App',
            '<external>/phpcs.exe',
        ];
        yield 'filesystem root does not leak' => ['/', '/workspace/project', '<external>'];
    }

    #[DataProvider('invalidSelectionCases')]
    public function testRejectsAmbiguousOrReservedSelections(string $selected): void
    {
        self::assertFalse(ExecutableEvidenceNormalizer::isValidSelection($selected));

        $this->expectException(\InvalidArgumentException::class);
        ExecutableEvidenceNormalizer::normalize($selected, '/workspace/project');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSelectionCases(): iterable
    {
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
        yield 'drive only' => ['C:'];
        yield 'drive relative' => ['C:tools\\phpcs'];
        yield 'file URI' => ['file:///home/alice/phpcs'];
        yield 'PHAR URI' => ['phar:///home/alice/tool.phar'];
        yield 'reserved external identity' => ['<external>'];
        yield 'reserved external path' => ['<external>/phpcs'];
        yield 'control character' => ["phpcs\n"];
    }
}

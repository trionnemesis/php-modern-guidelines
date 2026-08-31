<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification;

use ModernPhpGuidelines\Verification\ProjectPathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectPathNormalizerTest extends TestCase
{
    #[DataProvider('posixPaths')]
    public function testNormalizesPosixAndRelativePaths(string $input, string $expected): void
    {
        $normalizer = new ProjectPathNormalizer('/workspace/project');

        self::assertSame($expected, $normalizer->normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function posixPaths(): iterable
    {
        yield 'plain relative' => ['src/Foo.php', 'src/Foo.php'];
        yield 'dot and repeated separators' => ['./src//Foo.php', 'src/Foo.php'];
        yield 'lexical parent inside root' => ['src/Support/../Foo.php', 'src/Foo.php'];
        yield 'backslashes from analyzer output' => ['src\\Support\\Foo.php', 'src/Support/Foo.php'];
        yield 'absolute under root' => ['/workspace/project/src/Foo.php', 'src/Foo.php'];
        yield 'absolute with lexical parent' => ['/workspace/project/src/Support/../Foo.php', 'src/Foo.php'];
        yield 'unicode and spaces' => ['/workspace/project/來源/Hello World.php', '來源/Hello World.php'];
    }

    public function testNormalizesWindowsDrivePathsCaseInsensitively(): void
    {
        $normalizer = new ProjectPathNormalizer('C:\\Work\\App');

        self::assertSame('src/Foo.php', $normalizer->normalize('c:\\work\\app\\src\\Foo.php'));
    }

    public function testNormalizesUncPathsCaseInsensitively(): void
    {
        $normalizer = new ProjectPathNormalizer('\\\\Server\\Share\\App');

        self::assertSame('src/Foo.php', $normalizer->normalize('\\\\server\\share\\app\\src\\Foo.php'));
    }

    #[DataProvider('outsidePaths')]
    public function testRejectsPathsOutsideTheProjectWithoutEchoingThem(string $outside): void
    {
        $normalizer = new ProjectPathNormalizer('/private/checkout/project');

        try {
            $normalizer->normalize($outside);
            self::fail('Expected an outside-project path to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Finding path is outside the project root.', $e->getMessage());
            self::assertStringNotContainsString('/private/checkout', $e->getMessage());
            self::assertStringNotContainsString($outside, $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function outsidePaths(): iterable
    {
        yield 'segment boundary is not containment' => ['/private/checkout/project-other/Foo.php'];
        yield 'shorter ancestor' => ['/private/checkout'];
        yield 'unrelated absolute path' => ['/another/checkout/Foo.php'];
    }

    public function testRejectsRelativeParentEscape(): void
    {
        $normalizer = new ProjectPathNormalizer('/workspace/project');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Finding path escapes the project root.');

        $normalizer->normalize('../outside.php');
    }

    public function testRejectsDriveRelativePath(): void
    {
        $normalizer = new ProjectPathNormalizer('C:\\Work\\App');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported drive-relative form');

        $normalizer->normalize('C:src\\Foo.php');
    }

    #[DataProvider('uriPaths')]
    public function testRejectsUriPathsBeforeTheyCanLeakMachinePrefixes(string $path): void
    {
        $normalizer = new ProjectPathNormalizer('/workspace/project');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filesystem path, not a URI');

        $normalizer->normalize($path);
    }

    /** @return iterable<string, array{string}> */
    public static function uriPaths(): iterable
    {
        yield 'file URI' => ['file:///private/checkout/project/src/Foo.php'];
        yield 'PHAR URI' => ['phar:///private/checkout/tool.phar/src/Foo.php'];
    }

    public function testRejectsNullByteWithoutLeakingPath(): void
    {
        $normalizer = new ProjectPathNormalizer('/workspace/project');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain null bytes');

        $normalizer->normalize("src/Foo.php\0/private/value");
    }

    public function testRejectsEmptyFindingPath(): void
    {
        $normalizer = new ProjectPathNormalizer('/workspace/project');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $normalizer->normalize('');
    }

    #[DataProvider('projectRootPaths')]
    public function testRejectsAPathThatNamesOnlyTheProjectRoot(string $path): void
    {
        $normalizer = new ProjectPathNormalizer('/workspace/project');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must identify a project-relative entry');

        $normalizer->normalize($path);
    }

    /** @return iterable<string, array{string}> */
    public static function projectRootPaths(): iterable
    {
        yield 'absolute project root' => ['/workspace/project'];
        yield 'relative dot' => ['.'];
        yield 'relative segments collapsing to dot' => ['src/..'];
    }
}

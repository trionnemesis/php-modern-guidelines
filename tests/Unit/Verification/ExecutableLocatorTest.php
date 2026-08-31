<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification;

use ModernPhpGuidelines\Verification\ExecutableLocator;
use PHPUnit\Framework\TestCase;

final class ExecutableLocatorTest extends TestCase
{
    public function testRelativePathIsResolvedAgainstProjectRootInsteadOfProcessCwd(): void
    {
        $previousCwd = getcwd();
        self::assertIsString($previousCwd);
        self::assertTrue(chdir(sys_get_temp_dir()));

        try {
            $expected = realpath($this->repositoryRoot() . '/bin/php-modern-guidelines');
            self::assertIsString($expected);

            self::assertSame(
                $expected,
                (new ExecutableLocator())->locate('bin/php-modern-guidelines', $this->repositoryRoot()),
            );
        } finally {
            chdir($previousCwd);
        }
    }

    public function testRelativePathCannotResolveAnExecutableThatExistsOnlyUnderProcessCwd(): void
    {
        $previousCwd = getcwd();
        self::assertIsString($previousCwd);
        self::assertTrue(chdir($this->repositoryRoot()));

        try {
            self::assertNull((new ExecutableLocator())->locate(
                'bin/php-modern-guidelines',
                $this->repositoryRoot() . '/tests/fixtures/projects/comparison-range',
            ));
        } finally {
            chdir($previousCwd);
        }
    }

    public function testBareNameUsesPathLookup(): void
    {
        $resolvedPhp = realpath(PHP_BINARY);
        self::assertIsString($resolvedPhp);
        $previousPath = getenv('PATH');
        putenv('PATH=' . dirname($resolvedPhp));

        try {
            self::assertSame(
                $resolvedPhp,
                (new ExecutableLocator())->locate(basename($resolvedPhp), $this->repositoryRoot()),
            );
        } finally {
            putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        }
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}

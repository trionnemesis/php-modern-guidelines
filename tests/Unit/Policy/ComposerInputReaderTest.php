<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Policy;

use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use PHPUnit\Framework\TestCase;

final class ComposerInputReaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testProjectRootMissingThrowsWithPinnedMessage(): void
    {
        $missing = sys_get_temp_dir() . '/pmg-test-does-not-exist-' . bin2hex(random_bytes(4));

        $reader = new ComposerInputReader();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage(sprintf('Project root "%s" is not an existing directory.', $missing));

        $reader->read($missing);
    }

    public function testNoComposerJsonIsNotAnError(): void
    {
        $dir = $this->makeProject([]);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertFalse($inputs->composerJsonExists);
        self::assertNull($inputs->declaredConstraint);
        self::assertNull($inputs->conflictConstraint);
        self::assertFalse($inputs->platformKeyPresent);
        self::assertNull($inputs->platformOverride);
        self::assertFalse($inputs->composerLockExists);
        self::assertSame([], $inputs->warningCodes);
    }

    public function testProjectRootIsNormalisedWithRealpath(): void
    {
        $dir = $this->makeProject(['composer.json' => '{}']);

        $inputs = (new ComposerInputReader())->read($dir . '/');

        self::assertSame(realpath($dir), $inputs->projectRoot);
        self::assertStringEndsNotWith('/', $inputs->projectRoot);
    }

    public function testFilesystemRootIsNotErasedByNormalisation(): void
    {
        // rtrim(realpath('/'), '/') is '', which is not a legal policy.schema.json project_root.
        // Guard the assertion against sandboxes/CI runners that happen to have a real /composer.json.
        if (file_exists('/composer.json')) {
            self::markTestSkipped('/composer.json exists on this machine; cannot exercise the no-composer-json path at "/".');
        }

        $inputs = (new ComposerInputReader())->read('/');

        self::assertSame('/', $inputs->projectRoot);
        self::assertFalse($inputs->composerJsonExists);
    }

    public function testValidRequirePhpIsStoredVerbatim(): void
    {
        $dir = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}']);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertTrue($inputs->composerJsonExists);
        self::assertSame('^8.2', $inputs->declaredConstraint);
    }

    public function testMalformedComposerJsonThrowsPinnedMessage(): void
    {
        $dir = $this->makeProject(['composer.json' => '{"require": {']);

        $reader = new ComposerInputReader();

        try {
            $reader->read($dir);
            self::fail('Expected InputException.');
        } catch (InputException $e) {
            self::assertStringStartsWith('composer.json is not valid JSON: ', $e->getMessage());
            self::assertStringEndsWith('.', $e->getMessage());
        }
    }

    public function testMalformedComposerLockThrowsPinnedMessage(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}}',
            'composer.lock' => '{"platform-overrides": {',
        ]);

        $reader = new ComposerInputReader();

        try {
            $reader->read($dir);
            self::fail('Expected InputException.');
        } catch (InputException $e) {
            self::assertStringStartsWith('composer.lock is not valid JSON: ', $e->getMessage());
        }
    }

    public function testComposerJsonTopLevelArrayThrowsPinnedMessage(): void
    {
        // json_decode(..., true) turns both `{}` and `[]` into a PHP array, so a top-level JSON array
        // must be rejected explicitly instead of silently read as "no relevant settings".
        $dir = $this->makeProject(['composer.json' => '[]']);

        $reader = new ComposerInputReader();

        try {
            $reader->read($dir);
            self::fail('Expected InputException.');
        } catch (InputException $e) {
            self::assertSame(
                'composer.json is not valid JSON: top level must be a JSON object.',
                $e->getMessage(),
            );
        }
    }

    public function testComposerLockTopLevelArrayThrowsPinnedMessage(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}}',
            'composer.lock' => '[]',
        ]);

        $reader = new ComposerInputReader();

        try {
            $reader->read($dir);
            self::fail('Expected InputException.');
        } catch (InputException $e) {
            self::assertSame(
                'composer.lock is not valid JSON: top level must be a JSON object.',
                $e->getMessage(),
            );
        }
    }

    public function testNonStringRequirePhpThrowsWithGetDebugType(): void
    {
        $dir = $this->makeProject(['composer.json' => '{"require": {"php": 82}}']);

        $reader = new ComposerInputReader();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('composer.json require.php must be a string, got int.');

        $reader->read($dir);
    }

    public function testUnparseableRequirePhpIsNotRejectedByReader(): void
    {
        // The reader stores require.php verbatim without parsing it; parsing happens in the resolver.
        $dir = $this->makeProject(['composer.json' => '{"require": {"php": "not a constraint"}}']);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertSame('not a constraint', $inputs->declaredConstraint);
    }

    public function testNonStringConflictPhpThrows(): void
    {
        $dir = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}, "conflict": {"php": true}}']);

        $reader = new ComposerInputReader();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('composer.json conflict.php must be a string, got bool.');

        $reader->read($dir);
    }

    public function testUnparseableConflictPhpThrowsWithPinnedMessage(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "conflict": {"php": "not a constraint"}}',
        ]);

        $reader = new ComposerInputReader();

        try {
            $reader->read($dir);
            self::fail('Expected InputException.');
        } catch (InputException $e) {
            self::assertStringStartsWith(
                'Could not parse the PHP conflict constraint "not a constraint" from composer.json: ',
                $e->getMessage(),
            );
        }
    }

    public function testConflictPhpIsStoredVerbatimWhenParseable(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "conflict": {"php": "8.3.*"}}',
        ]);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertSame('8.3.*', $inputs->conflictConstraint);
    }

    public function testPlatformKeyAbsent(): void
    {
        $dir = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}']);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertFalse($inputs->platformKeyPresent);
        self::assertNull($inputs->platformOverride);
        self::assertSame([], $inputs->warningCodes);
    }

    public function testPlatformDisabledRaisesWarningCodeAndNullOverride(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "config": {"platform": {"php": false}}}',
        ]);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertTrue($inputs->platformKeyPresent);
        self::assertNull($inputs->platformOverride);
        self::assertSame(['input.platform_override_disabled'], $inputs->warningCodes);
    }

    public function testValidPlatformOverrideIsStoredVerbatim(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "config": {"platform": {"php": "8.2.0"}}}',
        ]);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertTrue($inputs->platformKeyPresent);
        self::assertSame('8.2.0', $inputs->platformOverride);
    }

    public function testMalformedPlatformOverrideThrows(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "config": {"platform": {"php": "8.x"}}}',
        ]);

        $reader = new ComposerInputReader();

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('composer.json config.platform.php must be "X.Y" or "X.Y.Z", got "8.x".');

        $reader->read($dir);
    }

    public function testPlatformOverrideWrongTypeThrows(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "config": {"platform": {"php": 123}}}',
        ]);

        $reader = new ComposerInputReader();

        $this->expectException(InputException::class);

        $reader->read($dir);
    }

    public function testNoComposerLockMeansLockExistsFalse(): void
    {
        $dir = $this->makeProject(['composer.json' => '{"require": {"php": "^8.2"}}']);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertFalse($inputs->composerLockExists);
        self::assertFalse($inputs->lockPlatformKeyPresent);
        self::assertNull($inputs->lockPlatformOverride);
    }

    public function testLockPlatformOverrideIsStoredVerbatim(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}}',
            'composer.lock' => '{"platform-overrides": {"php": "8.3.0"}}',
        ]);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertTrue($inputs->composerLockExists);
        self::assertTrue($inputs->lockPlatformKeyPresent);
        self::assertSame('8.3.0', $inputs->lockPlatformOverride);
    }

    public function testLockPlatformOverrideMalformedThrows(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}}',
            'composer.lock' => '{"platform-overrides": {"php": "8.x"}}',
        ]);

        $reader = new ComposerInputReader();

        $this->expectException(InputException::class);

        $reader->read($dir);
    }

    public function testMismatchBetweenJsonAndLockPlatformRaisesWarningCode(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "config": {"platform": {"php": "8.2.0"}}}',
            'composer.lock' => '{"platform-overrides": {"php": "8.3.0"}}',
        ]);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertSame('8.2.0', $inputs->platformOverride);
        self::assertSame('8.3.0', $inputs->lockPlatformOverride);
        self::assertContains('input.composer_lock_platform_mismatch', $inputs->warningCodes);
    }

    public function testMatchingJsonAndLockPlatformDoesNotRaiseMismatch(): void
    {
        $dir = $this->makeProject([
            'composer.json' => '{"require": {"php": "^8.2"}, "config": {"platform": {"php": "8.2.0"}}}',
            'composer.lock' => '{"platform-overrides": {"php": "8.2.0"}}',
        ]);

        $inputs = (new ComposerInputReader())->read($dir);

        self::assertSame([], $inputs->warningCodes);
    }

    /** @param array<string, string> $files relative-path => contents */
    private function makeProject(array $files): string
    {
        $dir = sys_get_temp_dir() . '/pmg-composer-input-reader-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        foreach ($files as $relativePath => $contents) {
            file_put_contents($dir . '/' . $relativePath, $contents);
        }

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

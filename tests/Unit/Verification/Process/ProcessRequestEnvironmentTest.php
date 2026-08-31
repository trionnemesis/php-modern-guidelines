<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification\Process;

use ModernPhpGuidelines\Verification\Process\ProcessRequest;
use PHPUnit\Framework\TestCase;

final class ProcessRequestEnvironmentTest extends TestCase
{
    public function testEnvironmentPolicyIsSortedSanitizedSecretFreeAndUsesAnExternalTempRoot(): void
    {
        $projectRoot = dirname(__DIR__, 4);
        $previousValues = [];
        foreach (['TMPDIR', 'TEMP', 'TMP'] as $name) {
            $previousValues[$name] = getenv($name);
        }

        putenv('TMPDIR=relative-temp');
        putenv('TEMP=' . $projectRoot);
        putenv('TMP=' . $projectRoot . '/tests');

        try {
            $request = new ProcessRequest(PHP_BINARY, [], $projectRoot, 1_000);
            $environment = $request->environment();
        } finally {
            foreach ($previousValues as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }

        $keys = array_keys($environment);
        $sortedKeys = $keys;
        sort($sortedKeys, SORT_STRING);
        self::assertSame($sortedKeys, $keys);
        self::assertSame('C', $environment['LANG']);
        self::assertSame('C', $environment['LC_ALL']);
        self::assertSame('UTC', $environment['TZ']);
        self::assertContains($environment['TMPDIR'], ['/tmp', '/var/tmp']);
        self::assertSame($environment['TMPDIR'], $environment['TEMP']);
        self::assertSame($environment['TMPDIR'], $environment['TMP']);
        self::assertNotSame('relative-temp', $environment['TMPDIR']);
        self::assertNotSame($projectRoot, $environment['TEMP']);
        self::assertFalse(str_starts_with($environment['TMP'], $projectRoot . '/'));

        foreach (['HOME', 'XDG_CONFIG_HOME', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'COMPOSER_AUTH'] as $name) {
            self::assertArrayNotHasKey($name, $environment);
        }
    }

    public function testEnvironmentFailsClosedWhenNoTemporaryDirectoryCanBeOutsideTheTarget(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !is_dir('/')) {
            self::markTestSkipped('The controlled temporary-directory policy is Linux/POSIX-specific.');
        }

        $request = new ProcessRequest(PHP_BINARY, [], '/', 1_000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside the process working directory');

        $request->environment();
    }
}

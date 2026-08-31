<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification\Process;

use ModernPhpGuidelines\Verification\Process\ProcessRequest;
use PHPUnit\Framework\TestCase;

final class ProcessRequestEnvironmentTest extends TestCase
{
    public function testEnvironmentPolicyIsSortedSanitizedAndSecretFree(): void
    {
        $request = new ProcessRequest(PHP_BINARY, [], dirname(__DIR__, 4), 1_000);
        $environment = $request->environment();

        $keys = array_keys($environment);
        $sortedKeys = $keys;
        sort($sortedKeys, SORT_STRING);
        self::assertSame($sortedKeys, $keys);
        self::assertSame('C', $environment['LANG']);
        self::assertSame('C', $environment['LC_ALL']);
        self::assertSame('UTC', $environment['TZ']);

        foreach (['HOME', 'XDG_CONFIG_HOME', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'COMPOSER_AUTH'] as $name) {
            self::assertArrayNotHasKey($name, $environment);
        }
    }
}

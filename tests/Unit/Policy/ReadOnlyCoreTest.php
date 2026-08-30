<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-006 read-only-core scan of src/: forbidden execution/write/socket primitives, fopen read-mode-only,
 * file_get_contents never a literal http URL. Scans all of src/, so it keeps protecting slices C and D
 * as they land.
 */
final class ReadOnlyCoreTest extends TestCase
{
    private const FORBIDDEN_ALTERNATION =
        'exec|shell_exec|proc_open|proc_close|passthru|popen|pclose|system|eval'
        . '|file_put_contents|fwrite|fputs|fputcsv|ftruncate|flock|unlink|rmdir|mkdir|rename|copy|touch'
        . '|chmod|chown|chgrp|symlink|link|umask|tempnam|tmpfile|move_uploaded_file'
        . '|fsockopen|pfsockopen|stream_socket_client|stream_socket_server|stream_context_create'
        . '|socket_create|curl_init|curl_setopt|curl_exec';

    #[DataProvider('phpFiles')]
    public function testNoForbiddenPrimitiveCallSites(string $path): void
    {
        $stripped = $this->strippedSource($path);

        $pattern = '/(?<![A-Za-z0-9_$>\\\\])(' . self::FORBIDDEN_ALTERNATION . ')\s*\(/';

        self::assertDoesNotMatchRegularExpression(
            $pattern,
            $stripped,
            sprintf('%s contains a forbidden execution/write/socket primitive call site.', $path),
        );
    }

    #[DataProvider('phpFiles')]
    public function testNoLiteralRemoteUrlPassedToFileGetContentsOrFopen(string $path): void
    {
        $stripped = $this->strippedSource($path);

        self::assertDoesNotMatchRegularExpression(
            '/file_get_contents\(\s*[\'"]https?/',
            $stripped,
            sprintf('%s calls file_get_contents() with a literal remote URL.', $path),
        );
        self::assertDoesNotMatchRegularExpression(
            '/fopen\(\s*[\'"]https?/',
            $stripped,
            sprintf('%s calls fopen() with a literal remote URL.', $path),
        );
    }

    #[DataProvider('phpFiles')]
    public function testNoShellExecSyntax(string $path): void
    {
        // Shell-exec syntax is the only construct that emits a bare (non-array) backtick token from
        // token_get_all(). A backtick inside a docblock, a string literal, or a heredoc never does.
        $tokens = token_get_all((string) file_get_contents($path));

        foreach ($tokens as $token) {
            self::assertNotSame(
                '`',
                is_array($token) ? null : $token,
                sprintf('%s contains shell-exec (backtick) syntax.', $path),
            );
        }
    }

    #[DataProvider('phpFiles')]
    public function testFopenIsReadOnly(string $path): void
    {
        $stripped = $this->strippedSource($path);

        if (preg_match_all('/fopen\(/', $stripped) === 0) {
            $this->expectNotToPerformAssertions();

            return;
        }

        preg_match_all('/fopen\([^)]*\)/', $stripped, $calls);

        foreach ($calls[0] as $call) {
            self::assertMatchesRegularExpression(
                "/fopen\\([^)]*,\\s*'(r|rb)'\\s*\\)/",
                $call,
                sprintf('%s calls fopen() with a non-read-only or non-literal mode: %s', $path, $call),
            );
        }
    }

    #[DataProvider('phpFiles')]
    public function testFileGetContentsTargetsAreNeverLiteralHttpUrls(string $path): void
    {
        $stripped = $this->strippedSource($path);

        self::assertDoesNotMatchRegularExpression(
            '/file_get_contents\(\s*[\'"]https?:/',
            $stripped,
            sprintf(
                '%s: every file_get_contents() in src/ must take a variable or a PackagePaths:: call, never a literal remote URL.',
                $path,
            ),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function phpFiles(): iterable
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = self::collectPhpFiles($root);
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            yield substr($file, strlen($root) + 1) => [$file];
        }
    }

    /** @return list<string> */
    private static function collectPhpFiles(string $dir): array
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }

        sort($entries, SORT_STRING);

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                array_push($files, ...self::collectPhpFiles($path));
            } elseif (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function strippedSource(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                // Replace a comment with its newlines so reported line numbers stay meaningful.
                $out .= ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}

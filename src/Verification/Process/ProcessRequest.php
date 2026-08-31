<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification\Process;

/**
 * Internal, already-resolved child-process request. There is deliberately no shell-command string
 * and no public CLI route to arbitrary arguments.
 */
final class ProcessRequest
{
    public const MAX_TIMEOUT_MILLISECONDS = 3_600_000;

    /** @var non-empty-list<string> */
    private const CONTROLLED_TEMPORARY_DIRECTORY_PATHS = [
        '/tmp',
        '/var/tmp',
    ];

    /** @var list<string> */
    public readonly array $arguments;

    public readonly string $workingDirectory;

    /**
     * @param array<array-key, mixed> $arguments validated and passed verbatim; an empty string is valid
     */
    public function __construct(
        public readonly string $executable,
        array $arguments,
        string $workingDirectory,
        public readonly int $timeoutMilliseconds,
    ) {
        if ($this->executable === '' || str_contains($this->executable, "\0")) {
            throw new \InvalidArgumentException('Process executable must be a non-empty path without null bytes.');
        }

        if (!self::isAbsolutePath($this->executable)) {
            throw new \InvalidArgumentException('Process executable must be an absolute path.');
        }

        if (!array_is_list($arguments)) {
            throw new \InvalidArgumentException('Process arguments must be a list.');
        }

        $validatedArguments = [];
        foreach ($arguments as $argument) {
            if (!is_string($argument)) {
                throw new \InvalidArgumentException('Every process argument must be a string.');
            }

            if (str_contains($argument, "\0")) {
                throw new \InvalidArgumentException('Process arguments must not contain null bytes.');
            }

            $validatedArguments[] = $argument;
        }
        $this->arguments = $validatedArguments;

        if ($this->timeoutMilliseconds < 1) {
            throw new \InvalidArgumentException('Process timeout must be at least one millisecond.');
        }
        if ($this->timeoutMilliseconds > self::MAX_TIMEOUT_MILLISECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'Process timeout must not exceed %d milliseconds.',
                self::MAX_TIMEOUT_MILLISECONDS,
            ));
        }

        if (!is_dir($workingDirectory)) {
            throw new \InvalidArgumentException('Process working directory must be an existing directory.');
        }

        $realWorkingDirectory = realpath($workingDirectory);
        if ($realWorkingDirectory === false) {
            throw new \InvalidArgumentException('Process working directory could not be resolved.');
        }

        $this->workingDirectory = self::withoutTrailingSeparator($realWorkingDirectory);
    }

    /** @return non-empty-list<string> */
    public function command(): array
    {
        return [$this->executable, ...$this->arguments];
    }

    /**
     * @return array<string, string> reviewed allow-list; proxy, credential and user-config variables are omitted
     */
    public function environment(): array
    {
        $environment = [
            'LANG' => 'C',
            'LC_ALL' => 'C',
            'NO_COLOR' => '1',
            'TERM' => 'dumb',
            'TZ' => 'UTC',
            'XDEBUG_MODE' => 'off',
        ];

        foreach (['PATH', 'PATHEXT', 'SystemRoot', 'WINDIR', 'COMSPEC'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '' && !str_contains($value, "\0")) {
                $environment[$name] = $value;
            }
        }

        $temporaryDirectory = $this->controlledTemporaryDirectory();
        $environment['TMPDIR'] = $temporaryDirectory;
        $environment['TEMP'] = $temporaryDirectory;
        $environment['TMP'] = $temporaryDirectory;

        ksort($environment, SORT_STRING);

        return $environment;
    }

    /**
     * Analyzer runtimes disagree about which temporary-directory variable they honor, so all three
     * point to the same fixed, canonical host directory. Parent values are deliberately ignored:
     * they may be relative (and therefore resolve below the project working directory) or may name
     * the target tree directly. If neither reviewed host path is outside the target, execution fails
     * closed before proc_open() is called.
     */
    private function controlledTemporaryDirectory(): string
    {
        foreach (self::CONTROLLED_TEMPORARY_DIRECTORY_PATHS as $path) {
            clearstatcache(true, $path);
            $realPath = realpath($path);
            if ($realPath === false || !is_dir($realPath) || !is_writable($realPath)) {
                continue;
            }

            $realPath = self::withoutTrailingSeparator($realPath);
            if (!$this->isInsideWorkingDirectory($realPath)) {
                return $realPath;
            }
        }

        throw new \RuntimeException(
            'No controlled writable temporary directory is available outside the process working directory.',
        );
    }

    private function isInsideWorkingDirectory(string $path): bool
    {
        if ($path === $this->workingDirectory) {
            return true;
        }

        $prefix = $this->workingDirectory === '/'
            ? '/'
            : $this->workingDirectory . '/';

        return str_starts_with($path, $prefix);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function withoutTrailingSeparator(string $path): string
    {
        if ($path === '/' || preg_match('/^[A-Za-z]:[\\\\\/]$/', $path) === 1) {
            return $path;
        }

        return rtrim($path, '/\\');
    }
}

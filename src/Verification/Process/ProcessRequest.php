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

        foreach (['PATH', 'PATHEXT', 'SystemRoot', 'WINDIR', 'COMSPEC', 'TMPDIR', 'TEMP', 'TMP'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '' && !str_contains($value, "\0")) {
                $environment[$name] = $value;
            }
        }

        ksort($environment, SORT_STRING);

        return $environment;
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

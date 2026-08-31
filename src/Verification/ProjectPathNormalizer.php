<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

/** Converts analyzer paths to deterministic project-relative paths without filesystem guessing. */
final class ProjectPathNormalizer
{
    /** @var array{volume: string, segments: list<string>, case_insensitive: bool} */
    private readonly array $root;

    public function __construct(string $projectRoot)
    {
        if ($projectRoot === '' || str_contains($projectRoot, "\0")) {
            throw new \InvalidArgumentException('Project root must be a non-empty absolute path without null bytes.');
        }

        $root = self::parseAbsolute($projectRoot);
        if ($root === null) {
            throw new \InvalidArgumentException('Project root must be an absolute path.');
        }

        $this->root = $root;
    }

    public function normalize(string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Finding path must not be empty.');
        }

        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Finding path must not contain null bytes.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]+:/', $path) === 1) {
            throw new \InvalidArgumentException('Finding path must be a filesystem path, not a URI.');
        }

        $absolute = self::parseAbsolute($path);
        if ($absolute === null) {
            if (preg_match('/^[A-Za-z]:/', $path) === 1) {
                throw new \InvalidArgumentException('Finding path uses an unsupported drive-relative form.');
            }

            $relative = self::normalizeSegments(str_replace('\\', '/', $path), false);
            if ($relative === []) {
                throw new \InvalidArgumentException('Finding path must identify a project-relative entry.');
            }

            return implode('/', $relative);
        }

        if (!self::same($this->root['volume'], $absolute['volume'], $this->root['case_insensitive'])) {
            throw new \InvalidArgumentException('Finding path is outside the project root.');
        }

        if (count($absolute['segments']) < count($this->root['segments'])) {
            throw new \InvalidArgumentException('Finding path is outside the project root.');
        }

        foreach ($this->root['segments'] as $index => $rootSegment) {
            $pathSegment = $absolute['segments'][$index];
            if (!self::same($rootSegment, $pathSegment, $this->root['case_insensitive'])) {
                throw new \InvalidArgumentException('Finding path is outside the project root.');
            }
        }

        $relative = array_slice($absolute['segments'], count($this->root['segments']));
        if ($relative === []) {
            throw new \InvalidArgumentException('Finding path must identify a project-relative entry.');
        }

        return implode('/', $relative);
    }

    /**
     * @return array{volume: string, segments: list<string>, case_insensitive: bool}|null
     */
    private static function parseAbsolute(string $path): ?array
    {
        $normalized = str_replace('\\', '/', $path);

        if (preg_match('/^([A-Za-z]):\/(.*)$/s', $normalized, $match) === 1) {
            return [
                'volume' => strtoupper($match[1]) . ':',
                'segments' => self::normalizeSegments($match[2], true),
                'case_insensitive' => true,
            ];
        }

        if (str_starts_with($normalized, '//')) {
            $withoutPrefix = substr($normalized, 2);
            $parts = explode('/', $withoutPrefix);
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                throw new \InvalidArgumentException('Absolute UNC path must include a server and share.');
            }

            $volume = '//' . $parts[0] . '/' . $parts[1];
            $rest = implode('/', array_slice($parts, 2));

            return [
                'volume' => $volume,
                'segments' => self::normalizeSegments($rest, true),
                'case_insensitive' => true,
            ];
        }

        if (str_starts_with($normalized, '/')) {
            return [
                'volume' => '/',
                'segments' => self::normalizeSegments(substr($normalized, 1), true),
                'case_insensitive' => false,
            ];
        }

        return null;
    }

    /** @return list<string> */
    private static function normalizeSegments(string $path, bool $absolute): array
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw new \InvalidArgumentException(
                        $absolute
                            ? 'Absolute path traverses above its filesystem root.'
                            : 'Finding path escapes the project root.',
                    );
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    private static function same(string $left, string $right, bool $caseInsensitive): bool
    {
        return $caseInsensitive ? strcasecmp($left, $right) === 0 : $left === $right;
    }
}

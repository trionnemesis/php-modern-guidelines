<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

/** Removes machine-specific path prefixes while retaining the caller-selected executable identity. */
final class ExecutableEvidenceNormalizer
{
    public static function isValidSelection(string $selected): bool
    {
        return $selected !== ''
            && preg_match('/[\x00-\x1F\x7F]/', $selected) !== 1
            && $selected !== '.'
            && $selected !== '..'
            && preg_match('/^[A-Za-z]:(?![\\\\\/])/', $selected) !== 1
            && preg_match('/^[A-Za-z][A-Za-z0-9+.-]+:/', $selected) !== 1
            && preg_match('~^<external>(?:[\\\\/]|$)~', $selected) !== 1;
    }

    public static function normalize(string $selected, string $projectRoot): string
    {
        if (!self::isValidSelection($selected)) {
            throw new \InvalidArgumentException('Invalid selected executable identity.');
        }

        if (!str_contains($selected, '/') && !str_contains($selected, '\\')) {
            return $selected;
        }

        try {
            return (new ProjectPathNormalizer($projectRoot))->normalize($selected);
        } catch (\InvalidArgumentException) {
            $normalized = rtrim(str_replace('\\', '/', $selected), '/');
            $basename = basename($normalized);

            return $basename === '' || $basename === '.' || $basename === '..'
                ? '<external>'
                : '<external>/' . $basename;
        }
    }
}

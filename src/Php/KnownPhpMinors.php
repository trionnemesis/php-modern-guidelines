<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Php;

/**
 * Single source of truth for the tool's known PHP minors.
 *
 * This class is the only place the minor list may be written down. Slice C and slice D must call it.
 */
final class KnownPhpMinors
{
    public const KNOWN_MIN = '8.2';
    public const KNOWN_MAX = '8.5';

    /** The authoritative list. KNOWN_MIN/KNOWN_MAX are the first and last elements, asserted by a test. */
    private const ALL = ['8.2', '8.3', '8.4', '8.5'];

    /** @return list<string> ascending, e.g. ['8.2', '8.3', '8.4', '8.5'] */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function contains(string $minor): bool
    {
        return in_array($minor, self::ALL, true);
    }

    /** @return int negative, zero or positive; numeric compare of major then minor. */
    public static function compare(string $a, string $b): int
    {
        [$majorA, $minorA] = self::parts($a);
        [$majorB, $minorB] = self::parts($b);

        return $majorA <=> $majorB ?: $minorA <=> $minorB;
    }

    public static function lowest(string $a, string $b): string
    {
        return self::compare($a, $b) <= 0 ? $a : $b;
    }

    public static function highest(string $a, string $b): string
    {
        return self::compare($a, $b) >= 0 ? $a : $b;
    }

    /** '8.4.19' | '8.4' -> '8.4'; anything not matching ^\d+\.\d+(\.\d+)?$ -> null */
    public static function normalizeToMinor(string $version): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)(?:\.\d+)?$/', $version, $matches) !== 1) {
            return null;
        }

        return $matches[1] . '.' . $matches[2];
    }

    /** '8.6' — the first minor above KNOWN_MAX. */
    public static function nextAfterKnownMax(): string
    {
        // Valid only while every known minor shares one major, asserted by KnownPhpMinorsTest.
        [$major, $minor] = self::parts(self::KNOWN_MAX);

        return $major . '.' . ($minor + 1);
    }

    /** @return array{0: int, 1: int} */
    private static function parts(string $minor): array
    {
        [$major, $m] = explode('.', $minor, 2);

        return [(int) $major, (int) $m];
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Support;

/**
 * `__DIR__`-relative locations of bundled data. Resolves correctly from a repo checkout and from
 * `vendor/trionnemesis/php-modern-guidelines/`, because both layouts contain `src/`, `schemas/` and
 * `resources/` as siblings. Do not use `getcwd()`, `Composer\InstalledVersions`, or `realpath()`
 * chasing for this.
 */
final class PackagePaths
{
    private static function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function rulesDirectory(): string
    {
        return self::packageRoot() . '/resources/rules';
    }

    public static function schemasDirectory(): string
    {
        return self::packageRoot() . '/schemas';
    }

    public static function ruleSchemaPath(): string
    {
        return self::packageRoot() . '/schemas/rule.schema.json';
    }

    public static function policySchemaPath(): string
    {
        return self::packageRoot() . '/schemas/policy.schema.json';
    }
}

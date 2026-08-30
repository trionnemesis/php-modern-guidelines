<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Support;

/**
 * The one deterministic encoder used by every JSON output and every fixture.
 */
final class JsonPrinter
{
    public const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $data May contain nested `\stdClass` values, which are encoded as
     *                                   JSON objects. `Rule::toArray()` uses this for the
     *                                   `package_constraints` empty-object case.
     */
    public static function encode(array $data): string
    {
        return (string) json_encode($data, self::FLAGS);
    }
}

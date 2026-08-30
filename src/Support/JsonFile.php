<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Support;

use ModernPhpGuidelines\Exception\InputException;

/**
 * Two static helpers, separated so a caller can read once and decode more than once: `read()` and
 * `decodeToArray()`. `readArray()` composes the two for slice B's single-decode callers.
 *
 * Never follows includes, never executes anything.
 */
final class JsonFile
{
    /** @throws InputException when the file cannot be read. */
    public static function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new InputException(sprintf('Could not read %s.', $path));
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InputException when $json is not valid JSON or its top level is not a JSON object.
     */
    public static function decodeToArray(string $json, string $label): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InputException(sprintf('%s is not valid JSON: %s.', $label, $e->getMessage()));
        }

        if (!is_array($decoded)) {
            throw new InputException(sprintf('%s is not valid JSON: top level must be a JSON object.', $label));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InputException
     */
    public static function readArray(string $path, string $label): array
    {
        return self::decodeToArray(self::read($path), $label);
    }
}

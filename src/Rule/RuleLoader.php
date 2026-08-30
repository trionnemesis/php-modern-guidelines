<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

use ModernPhpGuidelines\Exception\RuleDataException;
use ModernPhpGuidelines\Support\JsonSchemaValidator;

/**
 * Directory -> validated `RuleRegistry`. Schema-validates every file before registration; fails
 * closed on invalid/duplicate/misnamed rule data.
 */
final class RuleLoader
{
    public function __construct(private readonly JsonSchemaValidator $validator) {}

    /** @throws RuleDataException */
    public function loadDirectory(string $directory): RuleRegistry
    {
        if (!is_dir($directory)) {
            throw new RuleDataException(sprintf('Rule directory "%s" does not exist.', $directory));
        }

        $entries = @scandir($directory);
        if ($entries === false) {
            throw new RuleDataException(sprintf('Rule directory "%s" does not exist.', $directory));
        }

        $files = array_values(array_filter(
            $entries,
            static fn(string $entry): bool => str_ends_with($entry, '.json'),
        ));
        sort($files, SORT_STRING);

        $rules = [];
        /** @var array<string, string> $seenBy */
        $seenBy = [];

        foreach ($files as $basename) {
            $path = $directory . '/' . $basename;

            $raw = @file_get_contents($path);
            if ($raw === false) {
                throw new RuleDataException(sprintf('%s: could not be read.', $basename));
            }

            try {
                /** @var mixed $tree */
                $tree = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                /** @var mixed $data */
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuleDataException(sprintf('%s: not valid JSON: %s', $basename, $e->getMessage()));
            }

            if (!$tree instanceof \stdClass) {
                throw new RuleDataException(sprintf('%s: top level must be a JSON object.', $basename));
            }

            if (!is_array($data)) {
                throw new RuleDataException(sprintf('%s: top level must be a JSON object.', $basename));
            }

            $errors = $this->validator->validate($tree);
            if ($errors !== []) {
                $body = sprintf("Rule file \"%s\" does not match schemas/rule.schema.json:\n", $basename);
                foreach ($errors as $error) {
                    $body .= '  - ' . $error . "\n";
                }

                throw new RuleDataException(rtrim($body, "\n"));
            }

            $id = $data['id'] ?? null;
            if (!is_string($id)) {
                throw new RuleDataException(sprintf('%s: "id" must be a string.', $basename));
            }

            $category = $data['category'] ?? null;
            if (!is_string($category)) {
                throw new RuleDataException(sprintf('%s: "category" must be a string.', $basename));
            }

            if (basename($basename, '.json') !== $id) {
                throw new RuleDataException(sprintf('Rule file "%s" must be named "%s.json" to match its id.', $basename, $id));
            }

            if (explode('.', $id)[0] !== $category) {
                throw new RuleDataException(sprintf('Rule "%s" must start with its category segment "%s.".', $id, $category));
            }

            if (isset($seenBy[$id])) {
                throw new RuleDataException(sprintf('Duplicate rule id "%s" in %s; already defined by %s.', $id, $basename, $seenBy[$id]));
            }

            $seenBy[$id] = $basename;

            /** @var array<string, mixed> $data */
            $rules[] = Rule::fromArray($data);
        }

        return new RuleRegistry($rules);
    }
}

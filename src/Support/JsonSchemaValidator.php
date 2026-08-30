<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Support;

use ModernPhpGuidelines\Exception\RuleDataException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Exceptions\UnresolvedReferenceException;
use Opis\JsonSchema\Validator;

/**
 * Thin opis/json-schema wrapper. Takes a JSON *object tree* (`json_decode($raw)`, no assoc flag),
 * never an associative array — an array silently breaks `"package_constraints": {}"`, turning it into
 * a JSON array instead of a JSON object and failing schema validation for the entire seed catalogue.
 *
 * Never follows a remote `$ref`: only the local schema file passed to the constructor is registered
 * with the resolver, and no `http`/`https` protocol handler is registered. An unresolved `$ref` in the
 * schema surfaces as a `RuleDataException`, never as a network fetch.
 */
final class JsonSchemaValidator
{
    private readonly Validator $validator;

    private readonly string $schemaId;

    /** @throws RuleDataException when the schema file itself is missing or invalid. */
    public function __construct(private readonly string $schemaPath)
    {
        $raw = @file_get_contents($this->schemaPath);
        if ($raw === false) {
            throw new RuleDataException(sprintf('Schema file "%s" could not be read.', $this->schemaPath));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuleDataException(sprintf(
                'Schema file "%s" is not valid JSON: %s.',
                $this->schemaPath,
                $e->getMessage(),
            ));
        }

        $idValue = $decoded instanceof \stdClass && property_exists($decoded, '$id') ? $decoded->{'$id'} : null;

        if (!is_string($idValue) || $idValue === '') {
            throw new RuleDataException(sprintf(
                'Schema file "%s" is not a JSON object with a non-empty string "$id".',
                $this->schemaPath,
            ));
        }

        $this->schemaId = $idValue;

        $this->validator = new Validator();
        $resolver = $this->validator->resolver();
        if ($resolver === null) {
            throw new RuleDataException('The JSON schema validator has no schema resolver.');
        }

        $resolver->registerFile($this->schemaId, $this->schemaPath);
    }

    /**
     * @param  \stdClass $data A JSON object tree, i.e. `json_decode($raw)` WITHOUT the assoc flag.
     * @return list<string> sorted `"<data pointer>: <message>"` lines; empty means valid.
     * @throws RuleDataException when a `$ref` in the schema cannot be resolved.
     */
    public function validate(\stdClass $data): array
    {
        try {
            $result = $this->validator->validate($data, $this->schemaId);
        } catch (UnresolvedReferenceException $e) {
            throw new RuleDataException(sprintf(
                'Schema "%s" contains an unresolved reference: %s',
                $this->schemaId,
                $e->getMessage(),
            ));
        }

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();
        if ($error === null) {
            return [];
        }

        /**
         * opis/json-schema declares `ErrorFormatter::format()`'s return type as bare `array`; this
         * annotation records its documented, verified-in-this-environment shape (a map of JSON data
         * pointer to a list of message strings) rather than skipping any check.
         *
         * @var array<string, list<string>> $formatted
         */
        $formatted = (new ErrorFormatter())->format($error, true);

        $lines = [];
        foreach ($formatted as $pointer => $messages) {
            foreach ($messages as $message) {
                $lines[] = $pointer . ': ' . $message;
            }
        }

        $lines = array_values(array_unique($lines));
        sort($lines, SORT_STRING);

        return $lines;
    }
}

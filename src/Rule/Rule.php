<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

/**
 * Immutable rule value object.
 */
final class Rule
{
    public const SCHEMA_VERSION = '1.1.0';

    /**
     * @param array<string, string> $packageConstraints
     * @param list<RuleExample>     $examples           non-empty
     * @param list<RuleSource>      $sources            non-empty
     * @param list<string>          $notes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $summary,
        public readonly RuleCategory $category,
        public readonly RuleKind $kind,
        public readonly RulePriority $priority,
        public readonly ?string $introducedIn,
        public readonly ?string $deprecatedIn,
        public readonly ?string $removedIn,
        public readonly ?string $extension,
        public readonly array $packageConstraints,
        public readonly BehaviorChangeRisk $behaviorChangeRisk,
        public readonly string $newCodePolicy,
        public readonly string $existingCodePolicy,
        public readonly string $guideline,
        public readonly string $details,
        public readonly array $examples,
        public readonly RuleVerification $verification,
        public readonly array $sources,
        public readonly array $notes,
        public readonly ?string $supersededBy,
    ) {}

    /**
     * @param array<string, mixed> $data Already schema-validated by `RuleLoader` against
     *                                   `rule.schema.json` before this is called.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: self::str($data, 'id'),
            title: self::str($data, 'title'),
            summary: self::str($data, 'summary'),
            category: RuleCategory::from(self::str($data, 'category')),
            kind: RuleKind::from(self::str($data, 'kind')),
            priority: RulePriority::from(self::str($data, 'priority')),
            introducedIn: self::nullableStr($data, 'introduced_in'),
            deprecatedIn: self::nullableStr($data, 'deprecated_in'),
            removedIn: self::nullableStr($data, 'removed_in'),
            extension: self::nullableStr($data, 'extension'),
            packageConstraints: self::packageConstraints($data),
            behaviorChangeRisk: BehaviorChangeRisk::from(self::str($data, 'behavior_change_risk')),
            newCodePolicy: self::str($data, 'new_code_policy'),
            existingCodePolicy: self::str($data, 'existing_code_policy'),
            guideline: self::str($data, 'guideline'),
            details: self::str($data, 'details'),
            examples: self::examples($data),
            verification: self::verification($data),
            sources: self::sources($data),
            notes: self::notes($data),
            supersededBy: array_key_exists('superseded_by', $data) ? self::nullableStr($data, 'superseded_by') : null,
        );
    }

    /**
     * @return array<string, mixed> schema key order
     */
    public function toArray(): array
    {
        $constraints = $this->packageConstraints;
        ksort($constraints, SORT_STRING);
        // An empty map MUST serialise as {} and MUST validate against {"type": "object"}.
        // json_encode([]) emits [] and Helper::toJSON([]) stays an array — both fail the schema.
        $packageConstraints = (object) $constraints;

        $out = [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'category' => $this->category->value,
            'kind' => $this->kind->value,
            'priority' => $this->priority->value,
            'introduced_in' => $this->introducedIn,
            'deprecated_in' => $this->deprecatedIn,
            'removed_in' => $this->removedIn,
            'extension' => $this->extension,
            'package_constraints' => $packageConstraints,
            'behavior_change_risk' => $this->behaviorChangeRisk->value,
            'new_code_policy' => $this->newCodePolicy,
            'existing_code_policy' => $this->existingCodePolicy,
            'guideline' => $this->guideline,
            'details' => $this->details,
            'examples' => array_map(static fn(RuleExample $example): array => $example->toArray(), $this->examples),
            'verification' => $this->verification->toArray(),
            'sources' => array_map(static fn(RuleSource $source): array => $source->toArray(), $this->sources),
            'notes' => $this->notes,
        ];

        if ($this->supersededBy !== null) {
            $out['superseded_by'] = $this->supersededBy;
        }

        return $out;
    }

    /** @param array<array-key, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('Rule data key "%s" must be a string, got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function nullableStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \RuntimeException(sprintf('Rule data key "%s" must be a string or null, got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function packageConstraints(array $data): array
    {
        $value = $data['package_constraints'] ?? null;
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            throw new \RuntimeException(sprintf('Rule data key "package_constraints" must be an object, got %s.', get_debug_type($value)));
        }

        $constraints = [];
        foreach ($value as $package => $constraint) {
            if (!is_string($constraint)) {
                throw new \RuntimeException(sprintf('Rule data key "package_constraints.%s" must be a string.', (string) $package));
            }

            $constraints[(string) $package] = $constraint;
        }

        return $constraints;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<RuleExample>
     */
    private static function examples(array $data): array
    {
        $value = $data['examples'] ?? null;
        if (!is_array($value)) {
            throw new \RuntimeException('Rule data key "examples" must be an array.');
        }

        $examples = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('Rule data key "examples" must contain objects.');
            }

            $noAutomaticReplacement = $item['no_automatic_replacement'] ?? false;
            if (!is_bool($noAutomaticReplacement)) {
                throw new \RuntimeException('Rule data key "examples[].no_automatic_replacement" must be a bool.');
            }

            $examples[] = new RuleExample(
                before: array_key_exists('before', $item) ? self::stringList($item, 'before') : null,
                after: array_key_exists('after', $item) ? self::stringList($item, 'after') : null,
                noAutomaticReplacement: $noAutomaticReplacement,
            );
        }

        return $examples;
    }

    /** @param array<array-key, mixed> $data */
    private static function verification(array $data): RuleVerification
    {
        $value = $data['verification'] ?? null;
        if (!is_array($value)) {
            throw new \RuntimeException('Rule data key "verification" must be an object.');
        }

        return new RuleVerification(
            phpcompatibility: self::stringList($value, 'phpcompatibility'),
            phpstan: self::nullableStr($value, 'phpstan'),
            rector: self::nullableStr($value, 'rector'),
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<RuleSource>
     */
    private static function sources(array $data): array
    {
        $value = $data['sources'] ?? null;
        if (!is_array($value)) {
            throw new \RuntimeException('Rule data key "sources" must be an array.');
        }

        $sources = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('Rule data key "sources" must contain objects.');
            }

            $sources[] = new RuleSource(
                type: self::str($item, 'type'),
                url: self::str($item, 'url'),
                checkedAt: self::str($item, 'checked_at'),
            );
        }

        return $sources;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function notes(array $data): array
    {
        $value = $data['notes'] ?? [];

        return self::stringList(['notes' => $value], 'notes');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw new \RuntimeException(sprintf('Rule data key "%s" must be an array.', $key));
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \RuntimeException(sprintf('Rule data key "%s" must contain only strings.', $key));
            }

            $list[] = $item;
        }

        return $list;
    }
}

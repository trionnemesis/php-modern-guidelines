<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

/**
 * Immutable example: either `before[]` + `after[]`, or `no_automatic_replacement: true` — exclusively,
 * per `rule.schema.json`'s `$defs.example`.
 */
final class RuleExample
{
    /**
     * @param list<string>|null $before non-empty when present
     * @param list<string>|null $after  non-empty when present
     */
    public function __construct(
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly bool $noAutomaticReplacement,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->noAutomaticReplacement) {
            return ['no_automatic_replacement' => true];
        }

        return [
            'before' => $this->before,
            'after' => $this->after,
        ];
    }
}

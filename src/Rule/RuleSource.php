<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

final class RuleSource
{
    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly string $checkedAt,
    ) {}

    /** @return array{type: string, url: string, checked_at: string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->url,
            'checked_at' => $this->checkedAt,
        ];
    }
}

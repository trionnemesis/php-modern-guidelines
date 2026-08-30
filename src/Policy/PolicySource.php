<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

final class PolicySource
{
    public function __construct(
        public readonly SourceType $type,
        public readonly ?string $path,
        public readonly ?string $value,
    ) {}

    /** @return array{type: string, path: string|null, value: string|null} */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'path' => $this->path,
            'value' => $this->value,
        ];
    }
}

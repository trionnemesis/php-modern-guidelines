<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

final class RuleVerification
{
    public function __construct(
        public readonly ?string $phpcompatibility,
        public readonly ?string $phpstan,
        public readonly ?string $rector,
    ) {}

    /** @return array{phpcompatibility: string|null, phpstan: string|null, rector: string|null} */
    public function toArray(): array
    {
        return [
            'phpcompatibility' => $this->phpcompatibility,
            'phpstan' => $this->phpstan,
            'rector' => $this->rector,
        ];
    }
}

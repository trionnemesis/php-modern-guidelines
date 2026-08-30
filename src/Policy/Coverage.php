<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

final class Coverage
{
    public function __construct(
        public readonly CoverageStatus $status,
        public readonly string $knownMin,
        public readonly string $knownMax,
        public readonly bool $openUpperBound,
    ) {}

    /** @return array{status: string, known_min: string, known_max: string, open_upper_bound: bool} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'known_min' => $this->knownMin,
            'known_max' => $this->knownMax,
            'open_upper_bound' => $this->openUpperBound,
        ];
    }
}

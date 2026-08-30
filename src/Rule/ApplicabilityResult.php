<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

final class ApplicabilityResult
{
    /** @param list<string> $affectedMinors */
    public function __construct(
        public readonly ApplicabilityStatus $status,
        public readonly ApplicabilityAxis $axis,
        public readonly array $affectedMinors,
    ) {}

    public function isUsableAcrossRange(): bool
    {
        return $this->status === ApplicabilityStatus::Applicable;
    }

    /** @return array{status: string, axis: string, usable_across_range: bool, affected_minors: list<string>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'axis' => $this->axis->value,
            'usable_across_range' => $this->isUsableAcrossRange(),
            'affected_minors' => $this->affectedMinors,
        ];
    }
}

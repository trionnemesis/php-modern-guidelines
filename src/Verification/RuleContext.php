<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Rule\ApplicabilityResult;
use ModernPhpGuidelines\Rule\Rule;
use ModernPhpGuidelines\Rule\RuleSource;

final class RuleContext
{
    public function __construct(
        public readonly Rule $rule,
        public readonly ApplicabilityResult $applicability,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     applicability: array{status: string, axis: string, usable_across_range: bool, affected_minors: list<string>},
     *     sources: list<array{type: string, url: string, checked_at: string}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->rule->id,
            'applicability' => $this->applicability->toArray(),
            'sources' => array_map(
                static fn(RuleSource $source): array => $source->toArray(),
                $this->rule->sources,
            ),
        ];
    }
}

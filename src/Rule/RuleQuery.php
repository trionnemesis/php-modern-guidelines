<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

use ModernPhpGuidelines\Exception\InputException;

/**
 * Immutable filter spec. `$minor`'s shape is validated here, in the constructor; membership of
 * `$minor` in a policy's allowed minors is a separate check owned by `RuleRegistry::filter()`
 * (§4.4 — the two checks need different information and live in different classes).
 */
final class RuleQuery
{
    /**
     * @param list<RuleKind>            $kinds
     * @param list<RuleCategory>        $categories
     * @param list<RulePriority>        $priorities
     * @param list<ApplicabilityStatus> $statuses
     *
     * @throws InputException when $minor does not match the "X.Y" shape.
     */
    public function __construct(
        public readonly array $kinds = [],
        public readonly array $categories = [],
        public readonly array $priorities = [],
        public readonly array $statuses = [],
        public readonly ?string $extension = null,
        public readonly ?string $minor = null,
        public readonly bool $includeAll = false,
    ) {
        if ($this->minor !== null && preg_match('/^[0-9]+\.[0-9]+$/', $this->minor) !== 1) {
            throw new InputException(sprintf('--minor must be an "X.Y" PHP minor, got "%s".', $this->minor));
        }
    }
}

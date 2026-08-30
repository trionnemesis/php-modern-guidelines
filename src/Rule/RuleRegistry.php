<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Exception\RuleDataException;
use ModernPhpGuidelines\Exception\UnknownRuleException;
use ModernPhpGuidelines\Policy\ResolvedPolicy;

/**
 * Sorted, id-indexed, immutable collection.
 */
final class RuleRegistry
{
    /** @var list<Rule> ordered by id ascending (strcmp) */
    private readonly array $rules;

    /** @var array<string, Rule> */
    private readonly array $byId;

    /**
     * Sorts by id ascending (strcmp) and rejects duplicate ids.
     *
     * @param  list<Rule> $rules
     * @throws RuleDataException on a duplicate id
     */
    public function __construct(array $rules)
    {
        $sorted = $rules;
        usort($sorted, static fn(Rule $a, Rule $b): int => strcmp($a->id, $b->id));

        $byId = [];
        foreach ($sorted as $rule) {
            if (isset($byId[$rule->id])) {
                throw new RuleDataException(sprintf('Duplicate rule id "%s" in the rule registry.', $rule->id));
            }

            $byId[$rule->id] = $rule;
        }

        $this->rules = $sorted;
        $this->byId = $byId;
    }

    /** @return list<Rule> */
    public function all(): array
    {
        return $this->rules;
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    /** @throws UnknownRuleException */
    public function get(string $id): Rule
    {
        return $this->byId[$id] ?? throw new UnknownRuleException($id, $this->ids());
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->byId);
    }

    /**
     * @return list<array{rule: Rule, applicability: ApplicabilityResult}> registry order (id ascending)
     * @throws InputException on a malformed or out-of-range $query->minor (§4.4)
     */
    public function filter(RuleQuery $query, ResolvedPolicy $policy, ApplicabilityEvaluator $evaluator): array
    {
        if ($query->minor !== null && !$policy->allows($query->minor)) {
            throw new InputException(sprintf(
                '--minor %s is not in this project\'s allowed minors (%s).',
                $query->minor,
                implode(', ', $policy->allowedMinors),
            ));
        }

        $results = [];
        foreach ($this->rules as $rule) {
            $applicability = $evaluator->evaluate($rule, $policy);

            if (!$this->matches($rule, $applicability, $query)) {
                continue;
            }

            $results[] = ['rule' => $rule, 'applicability' => $applicability];
        }

        return $results;
    }

    public function count(): int
    {
        return count($this->rules);
    }

    private function matches(Rule $rule, ApplicabilityResult $applicability, RuleQuery $query): bool
    {
        if ($query->kinds !== [] && !in_array($rule->kind, $query->kinds, true)) {
            return false;
        }

        if ($query->categories !== [] && !in_array($rule->category, $query->categories, true)) {
            return false;
        }

        if ($query->priorities !== [] && !in_array($rule->priority, $query->priorities, true)) {
            return false;
        }

        if ($query->extension !== null && $rule->extension !== $query->extension) {
            return false;
        }

        if ($query->minor !== null && !in_array($query->minor, $applicability->affectedMinors, true)) {
            return false;
        }

        if ($query->statuses !== [] && !in_array($applicability->status, $query->statuses, true)) {
            return false;
        }

        if (!$query->includeAll && $applicability->status === ApplicabilityStatus::NotInRange) {
            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Exception\InputException;

final class VerificationAdapterRegistry
{
    /** @var array<string, VerificationAdapter> */
    private readonly array $adapters;

    /** @param list<VerificationAdapter> $adapters */
    public function __construct(array $adapters)
    {
        $byId = [];
        foreach ($adapters as $adapter) {
            $id = $adapter->id();
            if (preg_match('/^[a-z][a-z0-9_-]*$/', $id) !== 1) {
                throw new \LogicException(sprintf('Invalid verification adapter id "%s".', $id));
            }
            if (isset($byId[$id])) {
                throw new \LogicException(sprintf('Duplicate verification adapter id "%s".', $id));
            }

            $byId[$id] = $adapter;
        }
        ksort($byId, SORT_STRING);
        $this->adapters = $byId;
    }

    /** @throws InputException */
    public function get(string $id): VerificationAdapter
    {
        if (isset($this->adapters[$id])) {
            return $this->adapters[$id];
        }

        $expected = array_keys($this->adapters);
        throw new InputException(sprintf(
            'Unknown verification adapter "%s". Expected one of: %s.',
            $id,
            $expected === [] ? '(none)' : implode(', ', $expected),
        ));
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->adapters);
    }
}

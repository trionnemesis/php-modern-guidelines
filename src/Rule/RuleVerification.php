<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

final class RuleVerification
{
    /** @var list<string> */
    public readonly array $phpcompatibility;

    /**
     * @param array<array-key, mixed> $phpcompatibility sorted unique external sniff ids; empty means no proven mapping
     */
    public function __construct(
        array $phpcompatibility,
        public readonly ?string $phpstan,
        public readonly ?string $rector,
    ) {
        if (!array_is_list($phpcompatibility)) {
            throw new \LogicException('PHPCompatibility verification identifiers must be a list.');
        }

        foreach ($phpcompatibility as $identifier) {
            if (!is_string($identifier) || $identifier === '') {
                throw new \LogicException(
                    'PHPCompatibility verification identifiers must be non-empty strings.',
                );
            }
        }

        if (array_values(array_unique($phpcompatibility)) !== $phpcompatibility) {
            throw new \LogicException('PHPCompatibility verification identifiers must be unique.');
        }

        $sorted = $phpcompatibility;
        sort($sorted, SORT_STRING);
        if ($sorted !== $phpcompatibility) {
            throw new \LogicException('PHPCompatibility verification identifiers must be sorted.');
        }

        $this->phpcompatibility = $phpcompatibility;
    }

    /** @return array{phpcompatibility: list<string>, phpstan: string|null, rector: string|null} */
    public function toArray(): array
    {
        return [
            'phpcompatibility' => $this->phpcompatibility,
            'phpstan' => $this->phpstan,
            'rector' => $this->rector,
        ];
    }
}

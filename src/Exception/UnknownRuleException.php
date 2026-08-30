<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Exception;

/**
 * Unknown rule id requested (e.g. via `explain`). Maps to exit code 3.
 */
final class UnknownRuleException extends \RuntimeException
{
    /**
     * @param list<string> $knownIds sorted ascending
     */
    public function __construct(string $id, array $knownIds)
    {
        $suggestion = self::closestMatch($id, $knownIds);

        $message = sprintf('Unknown rule id "%s".', $id);
        if ($suggestion !== null) {
            $message .= sprintf(' Did you mean "%s"?', $suggestion);
        }

        parent::__construct($message);
    }

    /**
     * @param list<string> $knownIds sorted ascending; iterated in this order so the result is
     *                                deterministic
     */
    private static function closestMatch(string $id, array $knownIds): ?string
    {
        foreach ($knownIds as $knownId) {
            if (levenshtein($id, $knownId) <= 3) {
                return $knownId;
            }
        }

        return null;
    }
}

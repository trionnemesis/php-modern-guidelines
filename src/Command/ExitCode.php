<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Command;

/**
 * Exit-code constants for the whole CLI. Every command body dispatches its catch blocks onto these.
 */
final class ExitCode
{
    public const SUCCESS = 0;
    public const FAILURE = 1;
    public const INVALID_INPUT = 2;
    public const UNKNOWN_RULE = 3;
    public const UNRESOLVABLE_POLICY = 4;
    public const RULE_DATA_INVALID = 5;
}

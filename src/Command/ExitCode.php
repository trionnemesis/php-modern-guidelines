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
    public const VERIFICATION_FINDINGS = 6;
    public const ADAPTER_UNAVAILABLE = 7;
    public const ADAPTER_FAILED = 8;
    public const POLICY_PROJECTION_UNSUPPORTED = 9;
}

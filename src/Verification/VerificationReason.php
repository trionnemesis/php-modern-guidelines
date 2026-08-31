<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

final class VerificationReason
{
    public const EXECUTABLE_UNAVAILABLE = 'adapter.executable_unavailable';
    public const CAPABILITY_UNAVAILABLE = 'adapter.capability_unavailable';
    public const PROCESS_START_FAILED = 'adapter.process_start_failed';
    public const PROCESS_TIMED_OUT = 'adapter.process_timed_out';
    public const PROCESS_EXIT_FAILED = 'adapter.process_exit_failed';
    public const PROCESS_SIGNALED = 'adapter.process_signaled';
    public const OUTPUT_LIMIT_EXCEEDED = 'adapter.output_limit_exceeded';
    public const OUTPUT_INVALID = 'adapter.output_invalid';
    public const POLICY_PROJECTION_UNSUPPORTED = 'policy.projection_unsupported';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
        if ($this->code === '' || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $this->code) !== 1) {
            throw new \LogicException('A verification reason code must be a non-empty dotted identifier.');
        }

        if ($this->message === '' || preg_match('/[\x00-\x1F\x7F]/', $this->message) === 1) {
            throw new \LogicException('A verification reason message must not be empty or contain control characters.');
        }
    }

    /** @return array{code: string, message: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
        ];
    }

    /** @return list<string> */
    public static function unavailableCodes(): array
    {
        return [self::EXECUTABLE_UNAVAILABLE, self::CAPABILITY_UNAVAILABLE];
    }

    /** @return list<string> */
    public static function failureCodes(): array
    {
        return [
            self::PROCESS_START_FAILED,
            self::PROCESS_TIMED_OUT,
            self::PROCESS_EXIT_FAILED,
            self::PROCESS_SIGNALED,
            self::OUTPUT_LIMIT_EXCEEDED,
            self::OUTPUT_INVALID,
        ];
    }
}

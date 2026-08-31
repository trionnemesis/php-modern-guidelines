<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

/**
 * One executed verification invocation bound to the standard output it produced. The record and the
 * captured bytes always come from the same core-owned execution, so an adapter cannot pair one
 * process's output with a different invocation descriptor.
 */
final class VerificationExecution
{
    public function __construct(
        public readonly VerificationInvocation $invocation,
        public readonly string $stdout,
    ) {}
}

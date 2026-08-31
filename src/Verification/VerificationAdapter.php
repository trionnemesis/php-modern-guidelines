<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

interface VerificationAdapter
{
    public function id(): string;

    /** Build a side-effect-free projection plan. No external process may be launched in this phase. */
    public function plan(VerificationRequest $request): AdapterPlan;

    /**
     * Execute only a core-validated supported plan. Expected external failures are data, not
     * exceptions; an exception escaping this boundary is an internal bug and keeps exit code 1's
     * old meaning.
     */
    public function verify(VerificationRequest $request, AdapterPlan $plan): AdapterResult;
}

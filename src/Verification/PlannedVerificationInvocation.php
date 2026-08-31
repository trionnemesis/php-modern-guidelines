<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Verification\Process\ProcessRequest;

/** Immutable, deterministic description of an external verification process before it is run. */
final class PlannedVerificationInvocation
{
    public const DEFAULT_TIMEOUT_MILLISECONDS = 300_000;
    public const MAX_TIMEOUT_MILLISECONDS = ProcessRequest::MAX_TIMEOUT_MILLISECONDS;

    /** @var list<string> */
    public readonly array $policyMinors;

    /** @var list<string> */
    public readonly array $arguments;

    /**
     * @param array<array-key, mixed> $policyMinors
     * @param array<array-key, mixed> $arguments
     */
    public function __construct(
        public readonly string $id,
        array $policyMinors,
        public readonly string $executable,
        array $arguments,
        public readonly InvocationPurpose $purpose = InvocationPurpose::Analysis,
        public readonly InvocationWorkingDirectory $workingDirectory = InvocationWorkingDirectory::ProjectRoot,
        public readonly int $timeoutMilliseconds = self::DEFAULT_TIMEOUT_MILLISECONDS,
        public readonly InvocationEnvironment $environment = InvocationEnvironment::Sanitized,
    ) {
        if (preg_match('/^verification-[1-9][0-9]*$/', $this->id) !== 1) {
            throw new \LogicException('A planned verification invocation id must use the verification-N form.');
        }

        if (!array_is_list($policyMinors)) {
            throw new \LogicException('Planned verification invocation policy minors must be a list.');
        }

        if ($this->purpose === InvocationPurpose::Analysis && $policyMinors === []) {
            throw new \LogicException('A planned analysis invocation must name at least one projected PHP minor.');
        }
        if ($this->purpose === InvocationPurpose::ToolProbe && $policyMinors !== []) {
            throw new \LogicException('A planned tool probe must not claim projected PHP minors.');
        }

        $validatedMinors = [];
        foreach ($policyMinors as $minor) {
            if (!is_string($minor) || preg_match('/^[0-9]+\.[0-9]+$/', $minor) !== 1) {
                throw new \LogicException('Planned verification invocation policy minors must use the X.Y form.');
            }
            $validatedMinors[] = $minor;
        }
        if (array_values(array_unique($validatedMinors)) !== $validatedMinors) {
            throw new \LogicException('Planned verification invocation policy minors must be unique.');
        }

        $sortedMinors = $validatedMinors;
        usort($sortedMinors, static fn(string $left, string $right): int => version_compare($left, $right));
        if ($sortedMinors !== $validatedMinors) {
            throw new \LogicException('Planned verification invocation policy minors must be ordered ascending.');
        }
        $this->policyMinors = $validatedMinors;

        if ($this->executable === '' || preg_match('/[\x00-\x1F\x7F]/', $this->executable) === 1) {
            throw new \LogicException(
                'A planned verification invocation executable must not be empty or contain control characters.',
            );
        }

        if (!array_is_list($arguments)) {
            throw new \LogicException('Planned verification invocation arguments must be a list.');
        }

        $validatedArguments = [];
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \LogicException('Planned verification invocation arguments must not contain NUL.');
            }
            $validatedArguments[] = $argument;
        }
        $this->arguments = $validatedArguments;

        if ($this->timeoutMilliseconds < 1 || $this->timeoutMilliseconds > self::MAX_TIMEOUT_MILLISECONDS) {
            throw new \LogicException(sprintf(
                'A planned verification timeout must be between 1 and %d milliseconds.',
                self::MAX_TIMEOUT_MILLISECONDS,
            ));
        }
    }

    public static function fromExecuted(VerificationInvocation $invocation): self
    {
        return new self(
            $invocation->id,
            $invocation->policyMinors,
            $invocation->executable,
            $invocation->arguments,
            $invocation->purpose,
            $invocation->workingDirectory,
            $invocation->timeoutMilliseconds,
            $invocation->environment,
        );
    }

    public function matchesExecuted(VerificationInvocation $invocation): bool
    {
        return $this->id === $invocation->id
            && $this->policyMinors === $invocation->policyMinors
            && $this->executable === $invocation->executable
            && $this->arguments === $invocation->arguments
            && $this->purpose === $invocation->purpose
            && $this->timeoutMilliseconds === $invocation->timeoutMilliseconds;
    }

    /**
     * @return array{
     *     id: string,
     *     purpose: string,
     *     policy_minors: list<string>,
     *     executable: string,
     *     arguments: list<string>,
     *     working_directory: string,
     *     timeout_milliseconds: int,
     *     environment: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'purpose' => $this->purpose->value,
            'policy_minors' => $this->policyMinors,
            'executable' => $this->executable,
            'arguments' => $this->arguments,
            'working_directory' => $this->workingDirectory->value,
            'timeout_milliseconds' => $this->timeoutMilliseconds,
            'environment' => $this->environment->value,
        ];
    }
}

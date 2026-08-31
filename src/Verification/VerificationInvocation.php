<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Verification\Process\ProcessState;

final class VerificationInvocation
{
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
        public readonly ProcessState $status,
        public readonly ?int $exitCode,
        public readonly ?int $signal = null,
        public readonly InvocationPurpose $purpose = InvocationPurpose::Analysis,
        public readonly InvocationWorkingDirectory $workingDirectory = InvocationWorkingDirectory::ProjectRoot,
        public readonly int $timeoutMilliseconds = PlannedVerificationInvocation::DEFAULT_TIMEOUT_MILLISECONDS,
        public readonly InvocationEnvironment $environment = InvocationEnvironment::Sanitized,
    ) {
        if (preg_match('/^verification-[1-9][0-9]*$/', $this->id) !== 1) {
            throw new \LogicException('A verification invocation id must use the verification-N form.');
        }

        if (!array_is_list($policyMinors)) {
            throw new \LogicException('Verification invocation policy minors must be a list.');
        }

        if ($this->purpose === InvocationPurpose::Analysis && $policyMinors === []) {
            throw new \LogicException('A verification analysis invocation must name at least one projected PHP minor.');
        }
        if ($this->purpose === InvocationPurpose::ToolProbe && $policyMinors !== []) {
            throw new \LogicException('A verification tool probe must not claim projected PHP minors.');
        }

        $validatedMinors = [];
        foreach ($policyMinors as $minor) {
            if (!is_string($minor) || preg_match('/^[0-9]+\.[0-9]+$/', $minor) !== 1) {
                throw new \LogicException('Verification invocation policy minors must use the X.Y form.');
            }
            $validatedMinors[] = $minor;
        }
        if (array_values(array_unique($validatedMinors)) !== $validatedMinors) {
            throw new \LogicException('Verification invocation policy minors must be unique.');
        }

        $sortedMinors = $validatedMinors;
        usort($sortedMinors, static fn(string $left, string $right): int => version_compare($left, $right));
        if ($sortedMinors !== $validatedMinors) {
            throw new \LogicException('Verification invocation policy minors must be ordered ascending.');
        }
        $this->policyMinors = $validatedMinors;

        if ($this->executable === '' || preg_match('/[\x00-\x1F\x7F]/', $this->executable) === 1) {
            throw new \LogicException('A verification invocation executable must not be empty or contain control characters.');
        }

        if (!array_is_list($arguments)) {
            throw new \LogicException('Verification invocation arguments must be a list.');
        }

        $validatedArguments = [];
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new \LogicException('Verification invocation arguments must not contain NUL.');
            }
            $validatedArguments[] = $argument;
        }
        $this->arguments = $validatedArguments;

        if ($this->timeoutMilliseconds < 1
            || $this->timeoutMilliseconds > PlannedVerificationInvocation::MAX_TIMEOUT_MILLISECONDS) {
            throw new \LogicException(sprintf(
                'A verification timeout must be between 1 and %d milliseconds.',
                PlannedVerificationInvocation::MAX_TIMEOUT_MILLISECONDS,
            ));
        }

        if ($this->status === ProcessState::Exited) {
            if ($this->exitCode === null || $this->exitCode < 0 || $this->exitCode > 255) {
                throw new \LogicException('An exited verification invocation must carry an exit code from 0 through 255.');
            }
        } elseif ($this->exitCode !== null) {
            throw new \LogicException('A non-exited verification invocation must not carry an exit code.');
        }

        if ($this->status === ProcessState::Signaled) {
            if ($this->signal === null || $this->signal < 1) {
                throw new \LogicException('A signaled verification invocation must preserve a positive signal number.');
            }
        } elseif ($this->signal !== null) {
            throw new \LogicException('Only a signaled verification invocation may carry a signal number.');
        }
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
     *     status: string,
     *     exit_code: int|null,
     *     signal: int|null,
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
            'status' => $this->status->value,
            'exit_code' => $this->exitCode,
            'signal' => $this->signal,
        ];
    }
}

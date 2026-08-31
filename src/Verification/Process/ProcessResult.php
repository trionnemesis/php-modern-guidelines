<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification\Process;

/** Exact raw process capture. Stable verification output must normalize it before rendering. */
final class ProcessResult
{
    public function __construct(
        public readonly ProcessState $state,
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?int $signal = null,
    ) {
        if ($this->state === ProcessState::Exited
            && ($this->exitCode === null || $this->exitCode < 0 || $this->exitCode > 255)) {
            throw new \LogicException('An exited process result requires an exit code from 0 through 255.');
        }

        if ($this->state !== ProcessState::Exited && $this->exitCode !== null) {
            throw new \LogicException('Only an exited process result may carry an exit code.');
        }

        if ($this->state === ProcessState::Signaled) {
            if ($this->signal === null || $this->signal < 1) {
                throw new \LogicException('A signaled process result must preserve a positive signal number.');
            }
        } elseif ($this->signal !== null) {
            throw new \LogicException('Only a signaled process result may carry a signal number.');
        }
    }
}

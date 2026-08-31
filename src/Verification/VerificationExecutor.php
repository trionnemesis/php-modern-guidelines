<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

use ModernPhpGuidelines\Verification\Process\NativeProcessRunner;
use ModernPhpGuidelines\Verification\Process\ProcessRequest;
use ModernPhpGuidelines\Verification\Process\ProcessState;

/**
 * The ADR-008-mandated core-owned executor: the sole boundary through which a planned verification
 * invocation becomes a real child process. Neither collaborator is injectable — ExecutableLocator and
 * NativeProcessRunner are both final with no interface, so a constructor parameter for either would be
 * an injection seam no test could ever use. Every identity field of the returned invocation is copied
 * from the planned descriptor; only the process outcome (status/exit code/signal) and the captured
 * stdout come from the run itself, which is the mechanical binding ADR-008 requires.
 */
final class VerificationExecutor
{
    private readonly ExecutableLocator $locator;
    private readonly NativeProcessRunner $runner;

    public function __construct()
    {
        $this->locator = new ExecutableLocator();
        $this->runner = new NativeProcessRunner();
    }

    public function execute(
        VerificationRequest $request,
        PlannedVerificationInvocation $planned,
    ): VerificationExecution {
        $projectRoot = $request->policy->projectRoot;

        $located = $this->locator->locate($request->executable, $projectRoot);
        if ($located === null) {
            return self::startFailed($planned);
        }

        try {
            $processRequest = new ProcessRequest(
                $located,
                $planned->arguments,
                $projectRoot,
                $planned->timeoutMilliseconds,
            );
        } catch (\InvalidArgumentException) {
            return self::startFailed($planned);
        }

        // A \RuntimeException from ProcessRequest::environment() (no controlled writable temporary
        // directory outside the working directory) is deliberately not caught here: it is a host
        // precondition, not analyzer evidence, and the command layer already renders it as exit 1 with
        // empty stdout.
        $result = $this->runner->run($processRequest);

        $invocation = new VerificationInvocation(
            $planned->id,
            $planned->policyMinors,
            $planned->executable,
            $planned->arguments,
            $result->state,
            $result->exitCode,
            $result->signal,
            $planned->purpose,
            $planned->workingDirectory,
            $planned->timeoutMilliseconds,
            $planned->environment,
        );

        return new VerificationExecution($invocation, $result->stdout);
    }

    private static function startFailed(PlannedVerificationInvocation $planned): VerificationExecution
    {
        $invocation = new VerificationInvocation(
            $planned->id,
            $planned->policyMinors,
            $planned->executable,
            $planned->arguments,
            ProcessState::StartFailed,
            null,
            null,
            $planned->purpose,
            $planned->workingDirectory,
            $planned->timeoutMilliseconds,
            $planned->environment,
        );

        return new VerificationExecution($invocation, '');
    }
}

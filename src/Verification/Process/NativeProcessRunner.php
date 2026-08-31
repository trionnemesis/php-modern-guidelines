<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification\Process;

/**
 * The only native process-execution boundary in src/. Commands are always argv arrays, stdin is
 * closed immediately, and stdout/stderr are drained concurrently through non-blocking pipes. On
 * supported hosts, every command starts in a dedicated session/process group so timeout cleanup
 * reaches descendants as well as the process opened by proc_open().
 *
 * This is process isolation, not a filesystem sandbox. Adapters remain responsible for constructing
 * non-mutating tool invocations and proving target-tree integrity.
 *
 * @phpstan-type NativeProcessStatus array{
 *     command: string,
 *     pid: int,
 *     running: bool,
 *     signaled: bool,
 *     stopped: bool,
 *     exitcode: int,
 *     termsig: int,
 *     stopsig: int,
 *     cached?: bool
 * }
 */
final class NativeProcessRunner
{
    public const MAX_CAPTURE_BYTES_PER_STREAM = 8_388_608;

    private const READ_CHUNK_BYTES = 8192;
    private const MAX_READS_PER_PIPE_PER_CYCLE = 8;
    private const POLL_DELAY_MICROSECONDS = 10_000;
    private const ISOLATION_GRACE_MILLISECONDS = 100;
    private const TERMINATE_GRACE_MILLISECONDS = 250;
    private const KILL_GRACE_MILLISECONDS = 1_000;
    private const FINAL_DRAIN_MILLISECONDS = 100;
    private const TERMINATE_SIGNAL = 15;
    private const KILL_SIGNAL = 9;

    /** @var non-empty-list<string> */
    private const SESSION_LAUNCHER_PATHS = [
        '/usr/bin/setsid',
        '/bin/setsid',
    ];

    /**
     * Native execution fails closed unless non-blocking POSIX pipes, group signals, and a fixed
     * util-linux-compatible session launcher are available.
     */
    public static function isSupportedOnCurrentPlatform(): bool
    {
        return PHP_OS_FAMILY === 'Linux'
            && function_exists('posix_getpgid')
            && function_exists('posix_kill')
            && self::sessionLauncherPath() !== null;
    }

    public function run(ProcessRequest $request): ProcessResult
    {
        $sessionLauncher = self::sessionLauncherPath();
        if (!self::isSupportedOnCurrentPlatform() || $sessionLauncher === null) {
            return new ProcessResult(ProcessState::StartFailed, null, '', '');
        }

        clearstatcache(true, $request->executable);
        if (!is_file($request->executable) || !is_executable($request->executable)) {
            return new ProcessResult(ProcessState::StartFailed, null, '', '');
        }

        // Read the monotonic clock before opening the child so a clock failure cannot leak a process.
        $deadline = self::monotonicNanoseconds() + ($request->timeoutMilliseconds * 1_000_000.0);

        /** @var array<int, array{0: string, 1: string}> $descriptorSpec */
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = @proc_open(
            [$sessionLauncher, '--wait', ...$request->command()],
            $descriptorSpec,
            $pipes,
            $request->workingDirectory,
            $request->environment(),
            ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            return new ProcessResult(ProcessState::StartFailed, null, '', '');
        }

        $stdin = $pipes[0] ?? null;
        $stdoutPipe = $pipes[1] ?? null;
        $stderrPipe = $pipes[2] ?? null;
        if (!is_resource($stdin) || !is_resource($stdoutPipe) || !is_resource($stderrPipe)) {
            self::closePipe($stdin);
            self::closePipe($stdoutPipe);
            self::closePipe($stderrPipe);
            self::terminateUnverifiedProcess($process);
            proc_close($process);

            return new ProcessResult(ProcessState::StartFailed, null, '', '');
        }

        fclose($stdin);

        if (!stream_set_blocking($stdoutPipe, false) || !stream_set_blocking($stderrPipe, false)) {
            fclose($stdoutPipe);
            fclose($stderrPipe);
            self::terminateUnverifiedProcess($process);
            proc_close($process);

            return new ProcessResult(ProcessState::StartFailed, null, '', '');
        }

        $stdout = '';
        $stderr = '';
        try {
            [$initialStatus, $outputLimitExceeded] = self::waitForIsolatedProcessGroup(
                $process,
                $stdoutPipe,
                $stderrPipe,
                $stdout,
                $stderr,
            );
        } catch (\Throwable $e) {
            self::terminateUnverifiedProcess($process);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            proc_close($process);

            throw $e;
        }

        if ($outputLimitExceeded) {
            self::terminateUnverifiedProcess($process);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            proc_close($process);

            return new ProcessResult(ProcessState::OutputLimitExceeded, null, $stdout, $stderr);
        }

        if ($initialStatus === null) {
            self::terminateUnverifiedProcess($process);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            proc_close($process);

            return new ProcessResult(ProcessState::StartFailed, null, $stdout, $stderr);
        }

        $processGroupId = $initialStatus['pid'];
        /** @var NativeProcessStatus|null $terminalStatus */
        $terminalStatus = $initialStatus['running'] ? null : $initialStatus;
        $timedOut = false;
        $outputLimitExceeded = false;

        try {
            while ($terminalStatus === null) {
                [$stdoutChunk, $stdoutRead] = self::readAvailable($stdoutPipe);
                [$stderrChunk, $stderrRead] = self::readAvailable($stderrPipe);
                $stdoutWithinLimit = self::appendCaptured($stdout, $stdoutChunk);
                $stderrWithinLimit = self::appendCaptured($stderr, $stderrChunk);

                if (!$stdoutWithinLimit || !$stderrWithinLimit) {
                    $outputLimitExceeded = true;
                    self::signalProcessGroup($processGroupId, self::KILL_SIGNAL);
                    $terminalStatus = self::waitForStop(
                        $process,
                        $stdoutPipe,
                        $stderrPipe,
                        $stdout,
                        $stderr,
                        self::KILL_GRACE_MILLISECONDS,
                    );

                    break;
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $terminalStatus = $status;

                    break;
                }

                if (self::monotonicNanoseconds() >= $deadline) {
                    $timedOut = true;
                    self::signalProcessGroup($processGroupId, self::TERMINATE_SIGNAL);
                    $terminalStatus = self::waitForStop(
                        $process,
                        $stdoutPipe,
                        $stderrPipe,
                        $stdout,
                        $stderr,
                        self::TERMINATE_GRACE_MILLISECONDS,
                    );

                    // Always escalate the whole group. The leader may have honored SIGTERM while a
                    // background worker ignored it and retained access to the target tree.
                    self::signalProcessGroup($processGroupId, self::KILL_SIGNAL);
                    if ($terminalStatus === null) {
                        $terminalStatus = self::waitForStop(
                            $process,
                            $stdoutPipe,
                            $stderrPipe,
                            $stdout,
                            $stderr,
                            self::KILL_GRACE_MILLISECONDS,
                        );
                    }

                    break;
                }

                if (!$stdoutRead && !$stderrRead) {
                    usleep(self::POLL_DELAY_MICROSECONDS);
                }
            }

            if (!$timedOut && !$outputLimitExceeded) {
                // A normally exited leader must not leave a detached-in-practice worker running.
                // SIGKILL is sent before proc_close() can release/recycle the verified group id.
                self::signalProcessGroup($processGroupId, self::KILL_SIGNAL);
            }

            $drainStayedWithinLimit = self::drainAfterStop($stdoutPipe, $stderrPipe, $stdout, $stderr);
            if (!$timedOut && !$drainStayedWithinLimit) {
                $outputLimitExceeded = true;
            }
        } catch (\Throwable $e) {
            self::signalProcessGroup($processGroupId, self::KILL_SIGNAL);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            proc_close($process);

            throw $e;
        }

        fclose($stdoutPipe);
        fclose($stderrPipe);
        $closeExitCode = proc_close($process);

        if ($outputLimitExceeded) {
            return new ProcessResult(ProcessState::OutputLimitExceeded, null, $stdout, $stderr);
        }

        if ($timedOut) {
            return new ProcessResult(ProcessState::TimedOut, null, $stdout, $stderr);
        }

        if ($terminalStatus === null) {
            return new ProcessResult(ProcessState::StartFailed, null, $stdout, $stderr);
        }

        if ($terminalStatus['signaled'] || $terminalStatus['termsig'] > 0) {
            $signal = $terminalStatus['termsig'];
            if ($signal < 1) {
                throw new \RuntimeException('A signaled child process did not expose its terminating signal.');
            }

            return new ProcessResult(ProcessState::Signaled, null, $stdout, $stderr, $signal);
        }

        $statusExitCode = $terminalStatus['exitcode'];
        $exitCode = $statusExitCode >= 0
            ? $statusExitCode
            : ($closeExitCode >= 0 ? $closeExitCode : null);

        if ($exitCode === null) {
            return new ProcessResult(ProcessState::StartFailed, null, $stdout, $stderr);
        }

        return new ProcessResult(ProcessState::Exited, $exitCode, $stdout, $stderr);
    }

    /**
     * Wait until the fixed launcher has made its pid the process-group id. A process that exits
     * first is already bounded by the launcher; its pid remains the group id for descendant cleanup.
     *
     * @param resource $process
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     * @return array{NativeProcessStatus|null, bool} status and whether either output limit was exceeded
     */
    private static function waitForIsolatedProcessGroup(
        $process,
        $stdoutPipe,
        $stderrPipe,
        string &$stdout,
        string &$stderr,
    ): array {
        $deadline = self::monotonicNanoseconds() + (self::ISOLATION_GRACE_MILLISECONDS * 1_000_000.0);

        do {
            [$stdoutChunk, $stdoutRead] = self::readAvailable($stdoutPipe);
            [$stderrChunk, $stderrRead] = self::readAvailable($stderrPipe);
            $stdoutWithinLimit = self::appendCaptured($stdout, $stdoutChunk);
            $stderrWithinLimit = self::appendCaptured($stderr, $stderrChunk);
            if (!$stdoutWithinLimit || !$stderrWithinLimit) {
                return [null, true];
            }

            $status = proc_get_status($process);
            $pid = $status['pid'];
            if ($pid < 2) {
                return [null, false];
            }

            if (!$status['running'] || posix_getpgid($pid) === $pid) {
                return [$status, false];
            }

            if (!$stdoutRead && !$stderrRead) {
                usleep(self::POLL_DELAY_MICROSECONDS);
            }
        } while (self::monotonicNanoseconds() < $deadline);

        return [null, false];
    }

    private static function signalProcessGroup(int $processGroupId, int $signal): void
    {
        if ($processGroupId < 2) {
            throw new \LogicException('Refusing to signal an unsafe process-group id.');
        }

        @posix_kill(-$processGroupId, $signal);
    }

    /** @param resource $process */
    private static function terminateUnverifiedProcess($process): void
    {
        $status = proc_get_status($process);
        $pid = $status['pid'];
        if ($pid >= 2 && function_exists('posix_kill')) {
            // The fixed launcher normally establishes this group before proc_open() returns. Signal
            // that candidate group first, then the direct child, so setup failures cannot leak either.
            @posix_kill(-$pid, self::KILL_SIGNAL);
        }

        @proc_terminate($process, self::KILL_SIGNAL);
    }

    /**
     * @param resource $pipe
     * @return array{string, bool} bytes read and whether any bytes were available
     */
    private static function readAvailable($pipe): array
    {
        $output = '';

        for ($read = 0; $read < self::MAX_READS_PER_PIPE_PER_CYCLE; ++$read) {
            $chunk = fread($pipe, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                throw new \RuntimeException('Could not read a child-process output pipe.');
            }

            if ($chunk === '') {
                break;
            }

            $output .= $chunk;
        }

        return [$output, $output !== ''];
    }

    private static function appendCaptured(string &$captured, string $chunk): bool
    {
        if ($chunk === '') {
            return true;
        }

        $remaining = self::MAX_CAPTURE_BYTES_PER_STREAM - strlen($captured);
        if ($remaining <= 0) {
            return false;
        }

        if (strlen($chunk) <= $remaining) {
            $captured .= $chunk;

            return true;
        }

        $captured .= substr($chunk, 0, $remaining);

        return false;
    }

    /**
     * @param resource $process
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     * @return NativeProcessStatus|null
     */
    private static function waitForStop(
        $process,
        $stdoutPipe,
        $stderrPipe,
        string &$stdout,
        string &$stderr,
        int $graceMilliseconds,
    ): ?array {
        $deadline = self::monotonicNanoseconds() + ($graceMilliseconds * 1_000_000.0);

        do {
            [$stdoutChunk, $stdoutRead] = self::readAvailable($stdoutPipe);
            [$stderrChunk, $stderrRead] = self::readAvailable($stderrPipe);
            self::appendCaptured($stdout, $stdoutChunk);
            self::appendCaptured($stderr, $stderrChunk);

            $status = proc_get_status($process);
            if (!$status['running']) {
                return $status;
            }

            if (!$stdoutRead && !$stderrRead) {
                usleep(self::POLL_DELAY_MICROSECONDS);
            }
        } while (self::monotonicNanoseconds() < $deadline);

        return null;
    }

    /**
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     */
    private static function drainAfterStop($stdoutPipe, $stderrPipe, string &$stdout, string &$stderr): bool
    {
        $deadline = self::monotonicNanoseconds() + (self::FINAL_DRAIN_MILLISECONDS * 1_000_000.0);
        $withinLimit = true;

        do {
            [$stdoutChunk, $stdoutRead] = self::readAvailable($stdoutPipe);
            [$stderrChunk, $stderrRead] = self::readAvailable($stderrPipe);
            $withinLimit = self::appendCaptured($stdout, $stdoutChunk) && $withinLimit;
            $withinLimit = self::appendCaptured($stderr, $stderrChunk) && $withinLimit;

            if (feof($stdoutPipe) && feof($stderrPipe)) {
                return $withinLimit;
            }

            if (!$stdoutRead && !$stderrRead) {
                usleep(1_000);
            }
        } while (self::monotonicNanoseconds() < $deadline);

        return $withinLimit;
    }

    private static function monotonicNanoseconds(): float
    {
        return (float) hrtime(true);
    }

    private static function sessionLauncherPath(): ?string
    {
        foreach (self::SESSION_LAUNCHER_PATHS as $path) {
            clearstatcache(true, $path);
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @param mixed $pipe */
    private static function closePipe($pipe): void
    {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification\Process;

/**
 * The only native process-execution boundary in src/. Commands are always argv arrays, stdin is
 * closed immediately, and stdout/stderr are drained concurrently through non-blocking pipes.
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
    private const READ_CHUNK_BYTES = 8192;
    private const MAX_READS_PER_PIPE_PER_CYCLE = 8;
    private const POLL_DELAY_MICROSECONDS = 10_000;
    private const TERMINATE_GRACE_MILLISECONDS = 250;
    private const KILL_GRACE_MILLISECONDS = 1_000;
    private const FINAL_DRAIN_MILLISECONDS = 100;

    /** Windows anonymous pipes cannot provide the non-blocking capture/timeout guarantees required here. */
    public static function isSupportedOnCurrentPlatform(): bool
    {
        return PHP_OS_FAMILY !== 'Windows';
    }

    public function run(ProcessRequest $request): ProcessResult
    {
        if (!self::isSupportedOnCurrentPlatform()) {
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
            $request->command(),
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
            proc_terminate($process);
            proc_close($process);

            return new ProcessResult(ProcessState::StartFailed, null, '', '');
        }

        fclose($stdin);

        if (!stream_set_blocking($stdoutPipe, false) || !stream_set_blocking($stderrPipe, false)) {
            fclose($stdoutPipe);
            fclose($stderrPipe);
            proc_terminate($process);
            proc_close($process);

            return new ProcessResult(ProcessState::StartFailed, null, '', '');
        }

        $stdout = '';
        $stderr = '';
        /** @var NativeProcessStatus|null $terminalStatus */
        $terminalStatus = null;
        $timedOut = false;

        try {
            while (true) {
                [$stdoutChunk, $stdoutRead] = self::readAvailable($stdoutPipe);
                [$stderrChunk, $stderrRead] = self::readAvailable($stderrPipe);
                $stdout .= $stdoutChunk;
                $stderr .= $stderrChunk;

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $terminalStatus = $status;

                    break;
                }

                if (self::monotonicNanoseconds() >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process);
                    $terminalStatus = self::waitForStop(
                        $process,
                        $stdoutPipe,
                        $stderrPipe,
                        $stdout,
                        $stderr,
                        self::TERMINATE_GRACE_MILLISECONDS,
                    );

                    if ($terminalStatus === null) {
                        proc_terminate($process, 9);
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

            self::drainAfterStop($stdoutPipe, $stderrPipe, $stdout, $stderr);
        } catch (\Throwable $e) {
            proc_terminate($process);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            proc_close($process);

            throw $e;
        }

        fclose($stdoutPipe);
        fclose($stderrPipe);
        $closeExitCode = proc_close($process);

        if ($timedOut) {
            return new ProcessResult(ProcessState::TimedOut, null, $stdout, $stderr);
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
            $stdout .= $stdoutChunk;
            $stderr .= $stderrChunk;

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
    private static function drainAfterStop($stdoutPipe, $stderrPipe, string &$stdout, string &$stderr): void
    {
        $deadline = self::monotonicNanoseconds() + (self::FINAL_DRAIN_MILLISECONDS * 1_000_000.0);

        do {
            [$stdoutChunk, $stdoutRead] = self::readAvailable($stdoutPipe);
            [$stderrChunk, $stderrRead] = self::readAvailable($stderrPipe);
            $stdout .= $stdoutChunk;
            $stderr .= $stderrChunk;

            if (feof($stdoutPipe) && feof($stderrPipe)) {
                return;
            }

            if (!$stdoutRead && !$stderrRead) {
                usleep(1_000);
            }
        } while (self::monotonicNanoseconds() < $deadline);
    }

    private static function monotonicNanoseconds(): float
    {
        return (float) hrtime(true);
    }

    /** @param mixed $pipe */
    private static function closePipe($pipe): void
    {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}

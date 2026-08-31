<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification\Process;

/**
 * The only native process-execution boundary in src/. Commands are always argv arrays, stdin is
 * closed immediately, and stdout/stderr are drained concurrently through non-blocking pipes. On
 * supported hosts, every command starts in a dedicated user/PID namespace inside a dedicated
 * session/process group. Killing the namespace init terminates every process in that PID namespace,
 * including workers that create a new session or process group.
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
    private const SUPPORT_PROBE_TIMEOUT_MILLISECONDS = 1_000;
    private const TERMINATE_SIGNAL = 15;
    private const KILL_SIGNAL = 9;
    private const NAMESPACE_INIT_ARGUMENT = '--internal-pid-namespace-init';

    /** @var non-empty-list<string> */
    private const SESSION_LAUNCHER_PATHS = [
        '/usr/bin/setsid',
        '/bin/setsid',
    ];

    /** @var non-empty-list<string> */
    private const NAMESPACE_LAUNCHER_PATHS = [
        '/usr/bin/unshare',
        '/bin/unshare',
    ];

    /** @var non-empty-list<string> */
    private const SUPPORT_PROBE_EXECUTABLE_PATHS = [
        '/usr/bin/true',
        '/bin/true',
    ];

    private static ?bool $platformSupport = null;

    /**
     * Native execution fails closed unless POSIX group signals plus fixed util-linux-compatible
     * session and PID-namespace launchers are present and an operational namespace probe succeeds.
     */
    public static function isSupportedOnCurrentPlatform(): bool
    {
        if (self::$platformSupport !== null) {
            return self::$platformSupport;
        }

        $sessionLauncher = self::sessionLauncherPath();
        $namespaceLauncher = self::namespaceLauncherPath();
        $probeExecutable = self::supportProbeExecutablePath();
        if (PHP_OS_FAMILY !== 'Linux'
            || !function_exists('posix_getpgid')
            || !function_exists('posix_kill')
            || $sessionLauncher === null
            || $namespaceLauncher === null
            || $probeExecutable === null
            || !str_starts_with(PHP_BINARY, '/')
            || !is_file(PHP_BINARY)
            || !is_executable(PHP_BINARY)) {
            return self::$platformSupport = false;
        }

        return self::$platformSupport = self::pidNamespaceProbeSucceeds(
            $sessionLauncher,
            $namespaceLauncher,
            $probeExecutable,
        );
    }

    public function run(ProcessRequest $request): ProcessResult
    {
        $sessionLauncher = self::sessionLauncherPath();
        $namespaceLauncher = self::namespaceLauncherPath();
        if (!self::isSupportedOnCurrentPlatform()
            || $sessionLauncher === null
            || $namespaceLauncher === null) {
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
            3 => ['pipe', 'w'],
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = @proc_open(
            self::isolatedCommand(
                $sessionLauncher,
                $namespaceLauncher,
                self::namespaceInitCommand($request->command()),
            ),
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
        $statusPipe = $pipes[3] ?? null;
        if (!is_resource($stdin)
            || !is_resource($stdoutPipe)
            || !is_resource($stderrPipe)
            || !is_resource($statusPipe)) {
            self::closePipe($stdin);
            self::closePipe($stdoutPipe);
            self::closePipe($stderrPipe);
            self::closePipe($statusPipe);
            self::terminateUnverifiedProcess($process);
            proc_close($process);

            return new ProcessResult(ProcessState::StartFailed, null, '', '');
        }

        fclose($stdin);

        if (!stream_set_blocking($stdoutPipe, false) || !stream_set_blocking($stderrPipe, false)) {
            fclose($stdoutPipe);
            fclose($stderrPipe);
            fclose($statusPipe);
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
            fclose($statusPipe);
            proc_close($process);

            throw $e;
        }

        if ($outputLimitExceeded) {
            self::terminateUnverifiedProcess($process);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            fclose($statusPipe);
            proc_close($process);

            return new ProcessResult(ProcessState::OutputLimitExceeded, null, $stdout, $stderr);
        }

        if ($initialStatus === null) {
            self::terminateUnverifiedProcess($process);
            fclose($stdoutPipe);
            fclose($stderrPipe);
            fclose($statusPipe);
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
            fclose($statusPipe);
            proc_close($process);

            throw $e;
        }

        fclose($stdoutPipe);
        fclose($stderrPipe);
        proc_close($process);
        $namespaceStatus = stream_get_contents($statusPipe);
        fclose($statusPipe);

        if ($outputLimitExceeded) {
            return new ProcessResult(ProcessState::OutputLimitExceeded, null, $stdout, $stderr);
        }

        if ($timedOut) {
            return new ProcessResult(ProcessState::TimedOut, null, $stdout, $stderr);
        }

        if ($terminalStatus === null) {
            return new ProcessResult(ProcessState::StartFailed, null, $stdout, $stderr);
        }

        if (!is_string($namespaceStatus)) {
            return new ProcessResult(ProcessState::StartFailed, null, $stdout, $stderr);
        }

        $outcome = self::parseNamespaceStatus($namespaceStatus);
        if ($outcome === null || $outcome['state'] === ProcessState::StartFailed) {
            return new ProcessResult(ProcessState::StartFailed, null, $stdout, $stderr);
        }
        if ($outcome['state'] === ProcessState::Signaled) {
            return new ProcessResult(ProcessState::Signaled, null, $stdout, $stderr, $outcome['value']);
        }

        return new ProcessResult(ProcessState::Exited, $outcome['value'], $stdout, $stderr);
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

    /**
     * @internal Executed only as PID 1 by this file's fixed namespace-init entry point.
     * @param array<array-key, mixed> $command
     */
    public static function namespaceInitMain(array $command): int
    {
        if (PHP_OS_FAMILY !== 'Linux'
            || getmypid() !== 1
            || $command === []
            || !array_is_list($command)) {
            return 125;
        }

        $validatedCommand = [];
        foreach ($command as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                return 125;
            }

            $validatedCommand[] = $argument;
        }
        if (!str_starts_with($validatedCommand[0], '/')) {
            return 125;
        }

        $statusStream = @fopen('php://fd/3', 'wb');
        if (!is_resource($statusStream)) {
            return 125;
        }

        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
            3 => ['file', '/dev/null', 'w'],
        ];
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = @proc_open(
            $validatedCommand,
            $descriptorSpec,
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            $written = self::writeNamespaceStatus($statusStream, "start_failed\n");
            fclose($statusStream);

            return $written ? 0 : 125;
        }

        do {
            $terminalStatus = proc_get_status($process);
            if ($terminalStatus['running']) {
                usleep(self::POLL_DELAY_MICROSECONDS);
            }
        } while ($terminalStatus['running']);

        $closeExitCode = proc_close($process);
        if ($terminalStatus['signaled'] || $terminalStatus['termsig'] > 0) {
            $signal = $terminalStatus['termsig'];
            $statusLine = $signal > 0 ? sprintf("signal:%d\n", $signal) : "start_failed\n";
        } else {
            $statusExitCode = $terminalStatus['exitcode'];
            $exitCode = $statusExitCode >= 0
                ? $statusExitCode
                : ($closeExitCode >= 0 ? $closeExitCode : null);
            $statusLine = $exitCode === null
                ? "start_failed\n"
                : sprintf("exit:%d\n", $exitCode);
        }

        $written = self::writeNamespaceStatus($statusStream, $statusLine);
        fclose($statusStream);

        return $written ? 0 : 125;
    }

    /**
     * @param resource $stream
     */
    private static function writeNamespaceStatus($stream, string $status): bool
    {
        $offset = 0;
        $length = strlen($status);
        while ($offset < $length) {
            $written = fwrite($stream, substr($status, $offset));
            if ($written === false || $written === 0) {
                return false;
            }

            $offset += $written;
        }

        return true;
    }

    /** @return array{state: ProcessState, value: int}|null */
    private static function parseNamespaceStatus(string $status): ?array
    {
        if ($status === "start_failed\n") {
            return ['state' => ProcessState::StartFailed, 'value' => 0];
        }

        if (preg_match('/\\A(exit|signal):([0-9]+)\\n\\z/', $status, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[2];
        if ($matches[1] === 'exit' && $value >= 0 && $value <= 255) {
            return ['state' => ProcessState::Exited, 'value' => $value];
        }
        if ($matches[1] === 'signal' && $value >= 1 && $value <= 255) {
            return ['state' => ProcessState::Signaled, 'value' => $value];
        }

        return null;
    }

    /**
     * The existence of unshare(1) does not prove that the host permits an unprivileged user/PID
     * namespace. Probe the exact fixed launcher chain once with true(1); a denied uid_map, missing
     * kernel feature, or hung launcher leaves the entire runner unsupported.
     */
    private static function pidNamespaceProbeSucceeds(
        string $sessionLauncher,
        string $namespaceLauncher,
        string $probeExecutable,
    ): bool {
        /** @var array<int, list<string>> $descriptorSpec */
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
            3 => ['pipe', 'w'],
        ];

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = @proc_open(
            self::isolatedCommand(
                $sessionLauncher,
                $namespaceLauncher,
                self::namespaceInitCommand([$probeExecutable]),
            ),
            $descriptorSpec,
            $pipes,
            '/',
            [
                'LANG' => 'C',
                'LC_ALL' => 'C',
                'PATH' => '/usr/bin:/bin',
                'TZ' => 'UTC',
            ],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            return false;
        }

        $statusPipe = $pipes[3] ?? null;
        if (!is_resource($statusPipe)) {
            self::terminateUnverifiedProcess($process);
            proc_close($process);

            return false;
        }

        $deadline = self::monotonicNanoseconds()
            + (self::SUPPORT_PROBE_TIMEOUT_MILLISECONDS * 1_000_000.0);
        /** @var NativeProcessStatus|null $terminalStatus */
        $terminalStatus = null;

        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $terminalStatus = $status;

                break;
            }

            usleep(self::POLL_DELAY_MICROSECONDS);
        } while (self::monotonicNanoseconds() < $deadline);

        if ($terminalStatus === null) {
            self::terminateUnverifiedProcess($process);
            fclose($statusPipe);
            proc_close($process);

            return false;
        }

        $closeExitCode = proc_close($process);
        $namespaceStatus = stream_get_contents($statusPipe);
        fclose($statusPipe);
        if ($terminalStatus['signaled'] || $terminalStatus['termsig'] > 0) {
            return false;
        }

        $outcome = is_string($namespaceStatus) ? self::parseNamespaceStatus($namespaceStatus) : null;

        return ($terminalStatus['exitcode'] === 0 || $closeExitCode === 0)
            && $outcome !== null
            && $outcome['state'] === ProcessState::Exited
            && $outcome['value'] === 0;
    }

    /**
     * The fixed PHP namespace init becomes PID 1 and launches the analyzer as PID 2. unshare(1)'s
     * parent-death signal kills that init if the outer process group is killed; Linux then kills
     * every remaining process in the namespace, including descendants that called setsid()
     * themselves.
     *
     * @param non-empty-list<string> $command
     * @return non-empty-list<string>
     */
    private static function isolatedCommand(
        string $sessionLauncher,
        string $namespaceLauncher,
        array $command,
    ): array {
        return [
            $sessionLauncher,
            '--wait',
            $namespaceLauncher,
            '--user',
            '--map-current-user',
            '--pid',
            '--fork',
            '--kill-child=KILL',
            '--',
            ...$command,
        ];
    }

    /**
     * @param non-empty-list<string> $command
     * @return non-empty-list<string>
     */
    private static function namespaceInitCommand(array $command): array
    {
        return [
            PHP_BINARY,
            __FILE__,
            self::NAMESPACE_INIT_ARGUMENT,
            ...$command,
        ];
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

    private static function namespaceLauncherPath(): ?string
    {
        foreach (self::NAMESPACE_LAUNCHER_PATHS as $path) {
            clearstatcache(true, $path);
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function supportProbeExecutablePath(): ?string
    {
        foreach (self::SUPPORT_PROBE_EXECUTABLE_PATHS as $path) {
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

$nativeRunnerScript = $_SERVER['SCRIPT_FILENAME'] ?? null;
$nativeRunnerArguments = $_SERVER['argv'] ?? null;
if (PHP_SAPI === 'cli'
    && $nativeRunnerScript === __FILE__
    && is_array($nativeRunnerArguments)
    && ($nativeRunnerArguments[1] ?? null) === '--internal-pid-namespace-init') {
    exit(NativeProcessRunner::namespaceInitMain(array_slice($nativeRunnerArguments, 2)));
}

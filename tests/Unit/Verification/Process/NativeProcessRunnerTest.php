<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification\Process;

use ModernPhpGuidelines\Verification\Process\NativeProcessRunner;
use ModernPhpGuidelines\Verification\Process\ProcessRequest;
use ModernPhpGuidelines\Verification\Process\ProcessResult;
use ModernPhpGuidelines\Verification\Process\ProcessState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeProcessRunnerTest extends TestCase
{
    public function testPlatformSupportIsExplicit(): void
    {
        $hasSessionLauncher = false;
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) {
            if (is_file($path) && is_executable($path)) {
                $hasSessionLauncher = true;
                break;
            }
        }

        self::assertSame(
            PHP_OS_FAMILY === 'Linux'
                && function_exists('posix_getpgid')
                && function_exists('posix_kill')
                && $hasSessionLauncher,
            NativeProcessRunner::isSupportedOnCurrentPlatform(),
        );
    }

    public function testArgumentsArePassedVerbatimWithoutShellParsing(): void
    {
        $arguments = [
            'plain',
            'space separated',
            '"double quoted"',
            "'single quoted'",
            '$HOME',
            'semi;colon',
            'amp&ersand',
            'pipe|character',
            'redirect>character',
            'glob*?[abc]',
            "line\nbreak",
            '',
            '繁體中文',
        ];

        $result = $this->runner()->run($this->request(['argv', ...$arguments]));

        self::assertSame(ProcessState::Exited, $result->state);
        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stderr);
        self::assertSame($arguments, json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testShellMetacharactersCannotCreateAnInjectedFile(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('This sentinel payload uses POSIX shell syntax.');
        }

        $sentinel = sys_get_temp_dir() . '/php-modern-guidelines-injection-' . bin2hex(random_bytes(12));
        $payload = '; printf injected > ' . escapeshellarg($sentinel) . '; #';

        try {
            $result = $this->runner()->run($this->request(['argv', $payload]));

            self::assertSame(ProcessState::Exited, $result->state);
            self::assertSame([$payload], json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR));
            self::assertFileDoesNotExist($sentinel);
        } finally {
            if (is_file($sentinel)) {
                unlink($sentinel);
            }
        }
    }

    public function testStdoutAndStderrAreCapturedSeparately(): void
    {
        $result = $this->runner()->run($this->request(['output']));

        self::assertSame(ProcessState::Exited, $result->state);
        self::assertSame(0, $result->exitCode);
        self::assertSame("stdout: first\nstdout: second\n", $result->stdout);
        self::assertSame("stderr: first\nstderr: second\n", $result->stderr);
    }

    public function testChildReceivesOnlyTheSanitizedEnvironmentAllowList(): void
    {
        $previousSecret = getenv('PMG_PROCESS_SECRET');
        $previousTemporaryValues = [];
        foreach (['TMPDIR', 'TEMP', 'TMP'] as $name) {
            $previousTemporaryValues[$name] = getenv($name);
        }

        putenv('PMG_PROCESS_SECRET=must-not-leak');
        putenv('TMPDIR=relative-temp');
        putenv('TEMP=' . $this->projectRoot());
        putenv('TMP=' . $this->projectRoot() . '/tests');

        try {
            $result = $this->runner()->run($this->request(['environment']));
        } finally {
            putenv($previousSecret === false ? 'PMG_PROCESS_SECRET' : 'PMG_PROCESS_SECRET=' . $previousSecret);
            foreach ($previousTemporaryValues as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }

        self::assertSame(ProcessState::Exited, $result->state);
        /** @var array<string, string> $environment */
        $environment = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('C', $environment['LANG']);
        self::assertSame('C', $environment['LC_ALL']);
        self::assertSame('UTC', $environment['TZ']);
        self::assertSame('1', $environment['NO_COLOR']);
        self::assertContains($environment['TMPDIR'], ['/tmp', '/var/tmp']);
        self::assertSame($environment['TMPDIR'], $environment['TEMP']);
        self::assertSame($environment['TMPDIR'], $environment['TMP']);
        self::assertFalse(str_starts_with($environment['TMPDIR'], $this->projectRoot() . '/'));
        self::assertArrayNotHasKey('PMG_PROCESS_SECRET', $environment);
        self::assertArrayNotHasKey('HOME', $environment);
        self::assertArrayNotHasKey('HTTP_PROXY', $environment);
        self::assertArrayNotHasKey('HTTPS_PROXY', $environment);
    }

    public function testBothPipesAreDrainedBeyondTheirBufferSize(): void
    {
        $bytes = 262_144;
        $result = $this->runner()->run($this->request(['flood', (string) $bytes], 5_000));

        self::assertSame(ProcessState::Exited, $result->state);
        self::assertSame(0, $result->exitCode);
        self::assertSame($bytes, strlen($result->stdout));
        self::assertSame($bytes, strlen($result->stderr));
        self::assertSame(str_repeat('O', $bytes), $result->stdout);
        self::assertSame(str_repeat('E', $bytes), $result->stderr);
    }

    #[DataProvider('unboundedOutputStreams')]
    public function testOutputCaptureLimitTerminatesTheProcessGroup(string $stream): void
    {
        $startedAt = $this->monotonicNanoseconds();

        $result = $this->runner()->run($this->request(['flood-forever', $stream], 30_000));

        $finishedAt = $this->monotonicNanoseconds();
        self::assertSame(ProcessState::OutputLimitExceeded, $result->state);
        self::assertNull($result->exitCode);
        self::assertNull($result->signal);
        self::assertSame(
            $stream === 'stdout' ? NativeProcessRunner::MAX_CAPTURE_BYTES_PER_STREAM : 0,
            strlen($result->stdout),
        );
        self::assertSame(
            $stream === 'stderr' ? NativeProcessRunner::MAX_CAPTURE_BYTES_PER_STREAM : 0,
            strlen($result->stderr),
        );
        self::assertLessThan(10.0, ($finishedAt - $startedAt) / 1_000_000_000.0);
    }

    /** @return iterable<string, array{string}> */
    public static function unboundedOutputStreams(): iterable
    {
        yield 'stdout' => ['stdout'];
        yield 'stderr' => ['stderr'];
    }

    public function testNonZeroExitStatusIsPreservedWithBothOutputs(): void
    {
        $result = $this->runner()->run($this->request(['exit', '23']));

        self::assertSame(ProcessState::Exited, $result->state);
        self::assertSame(23, $result->exitCode);
        self::assertSame("before exit\n", $result->stdout);
        self::assertSame("exit stderr\n", $result->stderr);
    }

    public function testMaximumPortableExitStatusIsPreserved(): void
    {
        $result = $this->runner()->run($this->request(['exit', '255']));

        self::assertSame(ProcessState::Exited, $result->state);
        self::assertSame(255, $result->exitCode);
    }

    public function testResultRejectsExitStatusAbovePortableRange(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('0 through 255');

        new ProcessResult(ProcessState::Exited, 256, '', '');
    }

    public function testTimeoutTerminatesTheProcessAndPreservesOutputAlreadyWritten(): void
    {
        $startedAt = $this->monotonicNanoseconds();

        $result = $this->runner()->run($this->request(['sleep', '4000'], 500));

        $finishedAt = $this->monotonicNanoseconds();
        self::assertSame(ProcessState::TimedOut, $result->state);
        self::assertNull($result->exitCode);
        self::assertSame("before timeout\n", $result->stdout);
        self::assertSame("timeout stderr\n", $result->stderr);
        self::assertLessThan(3.0, ($finishedAt - $startedAt) / 1_000_000_000.0);
    }

    public function testTimeoutTerminatesBackgroundWorkersThatIgnoreTerminateSignal(): void
    {
        if (!function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('posix_kill')) {
            self::markTestSkipped('The descendant cleanup test requires PCNTL and POSIX signals.');
        }

        $sentinel = sys_get_temp_dir() . '/php-modern-guidelines-worker-' . bin2hex(random_bytes(12));
        $workerPid = null;

        try {
            $result = $this->runner()->run($this->request(['spawn-worker', $sentinel, '1200'], 500));

            self::assertSame(ProcessState::TimedOut, $result->state);
            self::assertSame('', $result->stderr);
            self::assertMatchesRegularExpression('/^worker:[1-9][0-9]*\n$/', $result->stdout);
            $workerPid = (int) substr(trim($result->stdout), strlen('worker:'));

            usleep(1_400_000);
            self::assertFileDoesNotExist($sentinel);
            self::assertFalse($this->processCanStillRun($workerPid), 'The analyzer background worker survived.');
        } finally {
            if (is_int($workerPid) && $workerPid > 1 && $this->processCanStillRun($workerPid)) {
                posix_kill($workerPid, 9);
            }
            if (is_file($sentinel)) {
                unlink($sentinel);
            }
        }
    }

    public function testSignalTerminationIsNotMisreportedAsAStartFailure(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_kill')) {
            self::markTestSkipped('Signal termination requires a POSIX process runtime.');
        }

        $result = $this->runner()->run($this->request(['signal']));

        self::assertSame(ProcessState::Signaled, $result->state);
        self::assertNull($result->exitCode);
        self::assertSame(15, $result->signal);
        self::assertSame("before signal\n", $result->stdout);
    }

    public function testMissingExecutableIsAStartFailureWithoutShellDiagnostics(): void
    {
        $missing = $this->projectRoot() . '/tests/fixtures/process/does-not-exist';
        $request = new ProcessRequest($missing, [], $this->projectRoot(), 1_000);

        $result = $this->runner()->run($request);

        self::assertSame(ProcessState::StartFailed, $result->state);
        self::assertNull($result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testRequestRejectsNullBytesBeforeCallingProcOpen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain null bytes');

        new ProcessRequest(PHP_BINARY, ["bad\0argument"], $this->projectRoot(), 1_000);
    }

    public function testRequestRejectsRelativeExecutable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute path');

        new ProcessRequest('php', [], $this->projectRoot(), 1_000);
    }

    public function testRequestRejectsNonListArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a list');

        new ProcessRequest(PHP_BINARY, ['named' => 'argument'], $this->projectRoot(), 1_000);
    }

    public function testRequestRejectsNonStringArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');

        new ProcessRequest(PHP_BINARY, [42], $this->projectRoot(), 1_000);
    }

    public function testRequestRejectsUnboundedZeroTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one millisecond');

        new ProcessRequest(PHP_BINARY, [], $this->projectRoot(), 0);
    }

    public function testRequestRejectsTimeoutAboveTheContractMaximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not exceed 3600000 milliseconds');

        new ProcessRequest(
            PHP_BINARY,
            [],
            $this->projectRoot(),
            ProcessRequest::MAX_TIMEOUT_MILLISECONDS + 1,
        );
    }

    private function runner(): NativeProcessRunner
    {
        if (!NativeProcessRunner::isSupportedOnCurrentPlatform()) {
            self::markTestSkipped('The native process runner requires Linux process-group isolation.');
        }

        return new NativeProcessRunner();
    }

    /** @param list<string> $childArguments */
    private function request(array $childArguments, int $timeoutMilliseconds = 2_000): ProcessRequest
    {
        return new ProcessRequest(
            PHP_BINARY,
            [$this->childPath(), ...$childArguments],
            $this->projectRoot(),
            $timeoutMilliseconds,
        );
    }

    private function childPath(): string
    {
        $path = realpath($this->projectRoot() . '/tests/fixtures/process/runner-child.php');
        self::assertIsString($path);

        return $path;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private function monotonicNanoseconds(): float
    {
        return (float) hrtime(true);
    }

    private function processCanStillRun(int $pid): bool
    {
        if ($pid < 2 || !posix_kill($pid, 0)) {
            return false;
        }

        $stat = @file_get_contents(sprintf('/proc/%d/stat', $pid));
        if (is_string($stat) && preg_match('/^[0-9]+ \(.+\) ([A-Z]) /', $stat, $matches) === 1) {
            return $matches[1] !== 'Z';
        }

        return true;
    }
}

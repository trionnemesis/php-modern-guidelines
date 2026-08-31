<?php

declare(strict_types=1);

/** @param resource $stream */
function write_all($stream, string $bytes): void
{
    $offset = 0;
    $length = strlen($bytes);

    while ($offset < $length) {
        $written = fwrite($stream, substr($bytes, $offset));
        if ($written === false || $written === 0) {
            exit(70);
        }

        $offset += $written;
    }
}

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$mode = $arguments[1] ?? '';

if ($mode === 'argv') {
    echo (string) json_encode(
        array_slice($arguments, 2),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ), "\n";
    exit(0);
}

if ($mode === 'output') {
    write_all(STDOUT, "stdout: first\nstdout: second\n");
    write_all(STDERR, "stderr: first\nstderr: second\n");
    exit(0);
}

if ($mode === 'flood') {
    $bytes = isset($arguments[2]) ? (int) $arguments[2] : 0;
    $chunkBytes = 8192;

    for ($written = 0; $written < $bytes; $written += $chunkBytes) {
        $length = min($chunkBytes, $bytes - $written);
        write_all(STDOUT, str_repeat('O', $length));
        write_all(STDERR, str_repeat('E', $length));
    }

    exit(0);
}

if ($mode === 'flood-forever') {
    $stream = ($arguments[2] ?? '') === 'stderr' ? STDERR : STDOUT;
    $chunk = str_repeat($stream === STDERR ? 'E' : 'O', 8192);

    while (!feof($stream)) {
        write_all($stream, $chunk);
    }

    exit(0);
}

if ($mode === 'exit') {
    write_all(STDOUT, "before exit\n");
    write_all(STDERR, "exit stderr\n");
    exit(isset($arguments[2]) ? (int) $arguments[2] : 1);
}

if ($mode === 'sleep') {
    write_all(STDOUT, "before timeout\n");
    write_all(STDERR, "timeout stderr\n");
    fflush(STDOUT);
    fflush(STDERR);
    usleep((isset($arguments[2]) ? (int) $arguments[2] : 1_000) * 1_000);
    write_all(STDOUT, "after timeout\n");
    exit(0);
}

$workerParentModes = [
    'spawn-worker',
    'spawn-escaped-worker-exit',
    'spawn-escaped-worker-timeout',
    'spawn-escaped-worker-output-overflow',
];

if (in_array($mode, $workerParentModes, true)) {
    $sentinel = $arguments[2] ?? '';
    $delayMilliseconds = isset($arguments[3]) ? (int) $arguments[3] : 1_200;
    if ($sentinel === '') {
        exit(64);
    }

    if ($mode === 'spawn-escaped-worker-exit') {
        if (!function_exists('pcntl_fork')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('posix_kill')
            || !function_exists('posix_setsid')) {
            exit(69);
        }

        $workerPid = pcntl_fork();
        if ($workerPid === -1) {
            exit(69);
        }
        if ($workerPid === 0) {
            if (posix_setsid() < 1) {
                exit(69);
            }

            pcntl_async_signals(true);
            pcntl_signal(15, SIG_IGN);
            usleep($delayMilliseconds * 1_000);
            file_put_contents($sentinel, "escaped worker survived\n");
            exit(0);
        }

        usleep(50_000);
        if (!posix_kill($workerPid, 0)) {
            exit(69);
        }

        write_all(STDOUT, sprintf("worker:%d\n", $workerPid));
        fflush(STDOUT);
        exit(0);
    }

    $workerMode = $mode === 'spawn-worker' ? 'delayed-write' : 'escaped-delayed-write';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $workerPipes = [];
    $worker = proc_open(
        [PHP_BINARY, __FILE__, $workerMode, $sentinel, (string) $delayMilliseconds],
        $descriptors,
        $workerPipes,
    );
    if (!is_resource($worker)) {
        exit(69);
    }

    fclose($workerPipes[0]);
    $ready = fgets($workerPipes[1]);
    $workerStatus = proc_get_status($worker);
    fclose($workerPipes[1]);
    fclose($workerPipes[2]);

    if ($ready !== "ready\n" || !$workerStatus['running']) {
        proc_terminate($worker, 9);
        proc_close($worker);
        exit(69);
    }

    write_all(STDOUT, sprintf("worker:%d\n", $workerStatus['pid']));
    fflush(STDOUT);

    if ($mode === 'spawn-escaped-worker-output-overflow') {
        $chunk = str_repeat('O', 8192);
        while (!feof(STDOUT)) {
            write_all(STDOUT, $chunk);
        }

        exit(0);
    }

    usleep(4_000_000);
    proc_close($worker);
    exit(0);
}

if ($mode === 'delayed-write' || $mode === 'escaped-delayed-write') {
    $sentinel = $arguments[2] ?? '';
    $delayMilliseconds = isset($arguments[3]) ? (int) $arguments[3] : 1_200;
    if ($sentinel === ''
        || !function_exists('pcntl_async_signals')
        || !function_exists('pcntl_signal')) {
        exit(69);
    }

    if ($mode === 'escaped-delayed-write'
        && (!function_exists('posix_setsid') || posix_setsid() < 1)) {
        exit(69);
    }

    pcntl_async_signals(true);
    pcntl_signal(15, SIG_IGN);
    write_all(STDOUT, "ready\n");
    fflush(STDOUT);
    usleep($delayMilliseconds * 1_000);
    file_put_contents($sentinel, "worker survived\n");
    exit(0);
}

if ($mode === 'signal') {
    write_all(STDOUT, "before signal\n");
    fflush(STDOUT);
    if (!function_exists('posix_kill')) {
        write_all(STDERR, "posix_kill unavailable\n");
        exit(69);
    }
    $pid = getmypid();
    if (!is_int($pid)) {
        exit(71);
    }
    posix_kill($pid, 15);
    usleep(100_000);
    exit(70);
}

if ($mode === 'environment') {
    echo (string) json_encode(getenv(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

write_all(STDERR, "unknown runner-child mode\n");
exit(64);

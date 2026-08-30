<?php

declare(strict_types=1);

/**
 * Prints one `<sha256 of entry contents>  <entry path>` line per file in a PHAR, sorted by entry path.
 *
 * The content-determinism proof of WORK-ORDER §3.4: two builds of the same tree are compared through
 * this digest rather than through the raw archive bytes, because a PHAR embeds a per-entry modification
 * time and an archive signature (ADR-007).
 *
 * CI-only. This is the only code in the repository that opens a PHAR; it lives outside `src/` so the
 * ADR-006 read-only scan is not weakened to accommodate it.
 */

$argvRaw = $_SERVER['argv'] ?? null;
if (!is_array($argvRaw) || count($argvRaw) !== 2 || !is_string($argvRaw[1])) {
    fwrite(STDERR, "usage: php tools/phar-content-digest.php <path-to.phar>\n");

    exit(2);
}

$path = $argvRaw[1];
if (!is_file($path)) {
    fwrite(STDERR, sprintf("not a file: %s\n", $path));

    exit(2);
}

$real = realpath($path);
if (false === $real) {
    fwrite(STDERR, sprintf("cannot resolve: %s\n", $path));

    exit(2);
}

$prefix = 'phar://' . str_replace('\\', '/', $real) . '/';

/** @var list<string> $lines */
$lines = [];

/** @var iterable<string, SplFileInfo> $entries */
$entries = new RecursiveIteratorIterator(new Phar($path));

foreach ($entries as $file) {
    $pathname = $file->getPathname();
    $digest = hash_file('sha256', $pathname);
    if (false === $digest) {
        fwrite(STDERR, sprintf("cannot hash entry: %s\n", $pathname));

        exit(1);
    }

    $inside = str_replace('\\', '/', $pathname);
    $entry = str_starts_with($inside, $prefix) ? substr($inside, strlen($prefix)) : $inside;
    $lines[] = sprintf('%s  %s', $digest, $entry);
}

sort($lines, SORT_STRING);

foreach ($lines as $line) {
    echo $line, "\n";
}

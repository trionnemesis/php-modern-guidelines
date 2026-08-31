<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Support;

/** Test-only byte/content tree snapshot shared by every verification adapter path. */
final class FixtureTreeSnapshot
{
    /** @return array<string, string> sorted relative path => deterministic type/content record */
    public static function capture(string $directory): array
    {
        $root = realpath($directory);
        if (!is_string($root) || !is_dir($root)) {
            throw new \InvalidArgumentException('Fixture tree root must be an existing directory.');
        }

        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));

            if ($item->isLink()) {
                $target = readlink($path);
                if (!is_string($target)) {
                    throw new \RuntimeException(sprintf('Could not read fixture symlink "%s".', $relative));
                }
                $snapshot[$relative] = 'link:' . $target;

                continue;
            }

            if ($item->isDir()) {
                $snapshot[$relative] = 'directory';

                continue;
            }

            if (!$item->isFile()) {
                $snapshot[$relative] = 'other';

                continue;
            }

            $size = filesize($path);
            $hash = hash_file('sha256', $path);
            if (!is_int($size) || !is_string($hash)) {
                throw new \RuntimeException(sprintf('Could not hash fixture file "%s".', $relative));
            }
            $snapshot[$relative] = sprintf('file:%d:sha256:%s', $size, $hash);
        }

        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }
}

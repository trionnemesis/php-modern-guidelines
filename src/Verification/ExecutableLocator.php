<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

/**
 * Read-only executable lookup. The selected spelling is retained in evidence; the resolved path is
 * used only to start a future adapter process and is never substituted into deterministic output.
 */
final class ExecutableLocator
{
    public function locate(string $selected, string $projectRoot): ?string
    {
        if (!ExecutableEvidenceNormalizer::isValidSelection($selected)) {
            return null;
        }

        if (str_contains($selected, '/') || str_contains($selected, '\\')) {
            $candidate = self::isAbsolutePath($selected)
                ? $selected
                : rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $selected;

            return $this->usablePath($candidate);
        }

        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }

        $extensions = [''];
        if (DIRECTORY_SEPARATOR === '\\') {
            $pathExt = getenv('PATHEXT');
            if (is_string($pathExt) && $pathExt !== '') {
                $extensions = [
                    '',
                    ...array_values(array_filter(explode(PATH_SEPARATOR, $pathExt))),
                ];
            }
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $resolved = $this->usablePath(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $selected . $extension);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function usablePath(string $candidate): ?string
    {
        $resolved = realpath($candidate);
        if (!is_string($resolved) || !is_file($resolved) || !is_executable($resolved)) {
            return null;
        }

        return $resolved;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}

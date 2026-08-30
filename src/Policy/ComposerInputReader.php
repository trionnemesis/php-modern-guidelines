<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Support\JsonFile;

/**
 * Read-only reader for `composer.json` / `composer.lock`. Fails closed on malformed JSON or malformed
 * values.
 *
 * Never interprets `require-dev`, `provide`, `replace`, `extra`, or Composer scripts. `conflict` is read
 * for its `php` key only and for nothing else. Never executes anything (ADR-006). Reads exactly two file
 * paths: `<project-root>/composer.json` and `<project-root>/composer.lock`.
 */
final class ComposerInputReader
{
    private const PLATFORM_PATTERN = '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/';

    public function __construct(
        private readonly MinorRangeCalculator $calculator = new MinorRangeCalculator(),
    ) {}

    /** @throws InputException */
    public function read(string $projectRoot): ProjectInputs
    {
        $normalizedRoot = $this->normalizeProjectRoot($projectRoot);

        $warningCodes = [];

        $composerJsonPath = $normalizedRoot . '/composer.json';
        $composerJsonExists = is_file($composerJsonPath);

        $declaredConstraint = null;
        $conflictConstraint = null;
        $platformKeyPresent = false;
        $platformOverride = null;

        if ($composerJsonExists) {
            $data = JsonFile::readArray($composerJsonPath, 'composer.json');

            $declaredConstraint = $this->readRequirePhp($data);
            $conflictConstraint = $this->readConflictPhp($data);

            [$platformKeyPresent, $platformOverride, $platformWarning] = $this->readPlatformOverride(
                $data,
                'composer.json config.platform.php',
            );
            if ($platformWarning !== null) {
                $warningCodes[] = $platformWarning;
            }
        }

        $composerLockPath = $normalizedRoot . '/composer.lock';
        $composerLockExists = is_file($composerLockPath);

        $lockPlatformKeyPresent = false;
        $lockPlatformOverride = null;

        if ($composerLockExists) {
            $lockData = JsonFile::readArray($composerLockPath, 'composer.lock');

            [$lockPlatformKeyPresent, $lockPlatformOverride] = $this->readLockPlatformOverride($lockData);
        }

        if ($platformOverride !== null && $lockPlatformOverride !== null && $platformOverride !== $lockPlatformOverride) {
            $warningCodes[] = 'input.composer_lock_platform_mismatch';
        }

        return new ProjectInputs(
            projectRoot: $normalizedRoot,
            declaredConstraint: $declaredConstraint,
            conflictConstraint: $conflictConstraint,
            composerJsonExists: $composerJsonExists,
            platformKeyPresent: $platformKeyPresent,
            platformOverride: $platformOverride,
            lockPlatformKeyPresent: $lockPlatformKeyPresent,
            lockPlatformOverride: $lockPlatformOverride,
            composerLockExists: $composerLockExists,
            warningCodes: $warningCodes,
        );
    }

    private function normalizeProjectRoot(string $projectRoot): string
    {
        if (!is_dir($projectRoot)) {
            throw new InputException(sprintf('Project root "%s" is not an existing directory.', $projectRoot));
        }

        $real = realpath($projectRoot);
        if ($real === false) {
            throw new InputException(sprintf('Project root "%s" is not an existing directory.', $projectRoot));
        }

        return rtrim($real, '/');
    }

    /** @param array<string, mixed> $data */
    private function readRequirePhp(array $data): ?string
    {
        $require = $data['require'] ?? null;
        if (!is_array($require) || !array_key_exists('php', $require)) {
            return null;
        }

        $value = $require['php'];
        if (!is_string($value) || $value === '') {
            throw new InputException(sprintf(
                'composer.json require.php must be a string, got %s.',
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function readConflictPhp(array $data): ?string
    {
        $conflict = $data['conflict'] ?? null;
        if (!is_array($conflict) || !array_key_exists('php', $conflict)) {
            return null;
        }

        $value = $conflict['php'];
        if (!is_string($value) || $value === '') {
            throw new InputException(sprintf(
                'composer.json conflict.php must be a string, got %s.',
                get_debug_type($value),
            ));
        }

        try {
            $this->calculator->parse($value);
        } catch (InputException $e) {
            throw new InputException(sprintf(
                'Could not parse the PHP conflict constraint "%s" from composer.json: %s',
                $value,
                $e->getMessage(),
            ));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed> $data
     * @return array{0: bool, 1: string|null, 2: string|null} [keyPresent, value, warningCode]
     */
    private function readPlatformOverride(array $data, string $label): array
    {
        $config = $data['config'] ?? null;
        if (!is_array($config)) {
            return [false, null, null];
        }

        $platform = $config['platform'] ?? null;
        if (!is_array($platform) || !array_key_exists('php', $platform)) {
            return [false, null, null];
        }

        $value = $platform['php'];

        if ($value === false) {
            return [true, null, 'input.platform_override_disabled'];
        }

        if (!is_string($value) || preg_match(self::PLATFORM_PATTERN, $value) !== 1) {
            throw new InputException(sprintf(
                '%s must be "X.Y" or "X.Y.Z", got %s.',
                $label,
                is_string($value) ? sprintf('"%s"', $value) : get_debug_type($value),
            ));
        }

        return [true, $value, null];
    }

    /**
     * @param  array<string, mixed> $lockData
     * @return array{0: bool, 1: string|null}
     */
    private function readLockPlatformOverride(array $lockData): array
    {
        $overrides = $lockData['platform-overrides'] ?? null;
        if (!is_array($overrides) || !array_key_exists('php', $overrides)) {
            return [false, null];
        }

        $value = $overrides['php'];

        if (!is_string($value) || preg_match(self::PLATFORM_PATTERN, $value) !== 1) {
            throw new InputException(sprintf(
                'composer.lock platform-overrides.php must be "X.Y" or "X.Y.Z", got %s.',
                is_string($value) ? sprintf('"%s"', $value) : get_debug_type($value),
            ));
        }

        return [true, $value];
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

/**
 * Immutable bag of raw evidence gathered from disk. Holds no `sources[]` — `sources[]` is built by the
 * resolver.
 */
final class ProjectInputs
{
    /**
     * @param list<string> $warningCodes warning codes raised while reading
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly ?string $declaredConstraint,
        public readonly ?string $conflictConstraint,
        public readonly bool $composerJsonExists,
        public readonly bool $platformKeyPresent,
        public readonly ?string $platformOverride,
        public readonly bool $lockPlatformKeyPresent,
        public readonly ?string $lockPlatformOverride,
        public readonly bool $composerLockExists,
        public readonly array $warningCodes,
    ) {}
}

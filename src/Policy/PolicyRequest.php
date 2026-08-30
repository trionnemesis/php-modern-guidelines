<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

use ModernPhpGuidelines\Exception\InputException;

final class PolicyRequest
{
    /**
     * @param string  $projectRoot RAW, exactly as the caller gave it (or `getcwd()`); NOT normalised
     *                             here — normalisation is the reader's job.
     * @param ?string $phpOverride RAW `--php` string, verbatim and unparsed; null when the flag is absent.
     * @throws InputException when $phpOverride is non-null and $mode is RuntimeObserved.
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly ResolutionMode $mode,
        public readonly ?string $phpOverride = null,
    ) {
        if ($this->phpOverride !== null && $this->mode === ResolutionMode::RuntimeObserved) {
            throw new InputException('--php cannot be combined with --mode=runtime-observed.');
        }
    }
}

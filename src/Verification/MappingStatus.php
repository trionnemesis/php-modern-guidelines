<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

enum MappingStatus: string
{
    case Mapped = 'mapped';
    case Unmapped = 'unmapped';
}

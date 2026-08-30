<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

enum CoverageStatus: string
{
    case Complete = 'complete';
    case CoverageGap = 'coverage_gap';
    case Unknown = 'unknown';
}

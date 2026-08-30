<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

enum ResolutionMode: string
{
    case RangeSafe = 'range-safe';
    case SingleTarget = 'single-target';
    case RuntimeObserved = 'runtime-observed';
}

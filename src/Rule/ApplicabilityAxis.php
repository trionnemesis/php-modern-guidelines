<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

enum ApplicabilityAxis: string
{
    case Feature = 'feature';
    case Lifecycle = 'lifecycle';
    case None = 'none';
}

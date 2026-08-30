<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

enum Confidence: string
{
    case Explicit = 'explicit';
    case Declared = 'declared';
    case Observed = 'observed';
    case Unresolved = 'unresolved';
}

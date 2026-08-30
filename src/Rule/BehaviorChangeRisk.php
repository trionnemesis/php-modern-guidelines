<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

enum BehaviorChangeRisk: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}

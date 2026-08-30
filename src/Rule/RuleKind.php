<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

enum RuleKind: string
{
    case Feature = 'feature';
    case ModernPreference = 'modern_preference';
    case Deprecated = 'deprecated';
    case Removed = 'removed';
    case CompatibilityGuard = 'compatibility_guard';
    case BehaviorChange = 'behavior_change';
}

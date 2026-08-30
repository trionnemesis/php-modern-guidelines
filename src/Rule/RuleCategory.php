<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Rule;

enum RuleCategory: string
{
    case Language = 'language';
    case Core = 'core';
    case Extension = 'extension';
}

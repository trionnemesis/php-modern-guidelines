<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

enum SourceType: string
{
    case CliPhpVersion = 'cli.php_version';
    case ProjectConfig = 'project.config';
    case ComposerRequirePhp = 'composer.require.php';
    case ComposerPlatformPhp = 'composer.platform.php';
    case ComposerLock = 'composer.lock';
    case Runtime = 'runtime';
}

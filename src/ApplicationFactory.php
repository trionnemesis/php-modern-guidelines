<?php

declare(strict_types=1);

namespace ModernPhpGuidelines;

use ModernPhpGuidelines\Command\VersionCommand;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    public const VERSION = '0.0.1';

    public static function create(): Application
    {
        $application = new Application('php-modern-guidelines', self::VERSION);
        $application->add(new VersionCommand());

        return $application;
    }
}

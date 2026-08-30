<?php

declare(strict_types=1);

namespace ModernPhpGuidelines;

use ModernPhpGuidelines\Command\DoctorCommand;
use ModernPhpGuidelines\Command\ExplainCommand;
use ModernPhpGuidelines\Command\ListCommand;
use ModernPhpGuidelines\Command\ResolveCommand;
use ModernPhpGuidelines\Command\VersionCommand;
use Symfony\Component\Console\Application;

final class ApplicationFactory
{
    public const VERSION = '0.2.0';

    public static function create(): Application
    {
        $application = new Application('php-modern-guidelines', self::VERSION);
        // Application::add() is deprecated in favour of addCommand() as of symfony/console 7.4, but
        // addCommand() does not exist on the symfony/console ^6.4 floor this package still supports
        // (composer.json), so add() is kept for all five commands (see WORK-ORDER.md §5.8).
        $application->add(new VersionCommand());
        $application->add(new ResolveCommand());
        $application->add(new ListCommand());
        $application->add(new ExplainCommand());
        $application->add(new DoctorCommand());

        return $application;
    }
}

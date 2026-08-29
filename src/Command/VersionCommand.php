<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Command;

use ModernPhpGuidelines\ApplicationFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'version', description: 'Display the php-modern-guidelines version.')]
final class VersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('php-modern-guidelines ' . ApplicationFactory::VERSION);

        return Command::SUCCESS;
    }
}

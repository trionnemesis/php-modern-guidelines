<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Command;

use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\ResolutionMode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared helper that declares and parses `--project-root`, `--php`, `--mode` and `--json` on a
 * command, and builds a `PolicyRequest` from them. A final class with static methods, not a trait,
 * to keep PHPStan level max happy.
 */
final class PolicyOptions
{
    private function __construct() {}

    /** Declares --project-root, --php, --mode, --json on the command. */
    public static function configure(Command $command): void
    {
        $command
            ->addOption('project-root', 'r', InputOption::VALUE_REQUIRED, 'Directory to read composer.json / composer.lock from.')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'Explicit PHP version or Composer constraint; recorded as cli.php_version.')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'range-safe / single-target / runtime-observed.', ResolutionMode::RangeSafe->value)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON on stdout instead of human text.');
    }

    /**
     * Parses --project-root, --php and --mode into a request.
     *
     * @throws InputException on an unknown --mode value, or on the --php/runtime-observed
     *                        contradiction raised by PolicyRequest::__construct.
     */
    public static function request(InputInterface $input): PolicyRequest
    {
        $projectRootOption = $input->getOption('project-root');
        if ($projectRootOption === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new InputException('Could not determine the current working directory; pass --project-root explicitly.');
            }

            $projectRoot = $cwd;
        } else {
            if (!is_string($projectRootOption)) {
                throw new InputException('--project-root must be a string.');
            }

            $projectRoot = $projectRootOption;
        }

        $modeOption = $input->getOption('mode');
        if (!is_string($modeOption)) {
            throw new InputException('--mode must be a string.');
        }

        $mode = ResolutionMode::tryFrom($modeOption);
        if ($mode === null) {
            throw new InputException(sprintf(
                'Unknown mode "%s". Expected one of: %s.',
                $modeOption,
                implode(', ', array_column(ResolutionMode::cases(), 'value')),
            ));
        }

        $phpOption = $input->getOption('php');
        if ($phpOption !== null && !is_string($phpOption)) {
            throw new InputException('--php must be a string.');
        }

        return new PolicyRequest($projectRoot, $mode, $phpOption);
    }

    /** The --json flag as a bool. */
    public static function json(InputInterface $input): bool
    {
        return (bool) $input->getOption('json');
    }
}

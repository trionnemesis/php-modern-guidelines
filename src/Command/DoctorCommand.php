<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Command;

use ModernPhpGuidelines\Diagnostics\DiagnosticCheck;
use ModernPhpGuidelines\Diagnostics\DiagnosticReport;
use ModernPhpGuidelines\Diagnostics\DoctorRunner;
use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Support\JsonPrinter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `doctor` — bounded, read-only diagnosis of this tool's own inputs and installation for a project
 * (WORK-ORDER.md §5). Rendering and exit-code selection only; every check itself lives in
 * `DoctorRunner`.
 *
 * D19: unlike every other command, `doctor` prints its complete report on stdout even when it exits
 * non-zero — the report *is* the diagnosis, never a partial document. The one exception is a mistake
 * in `doctor`'s own options, rejected before any check runs, which prints nothing on stdout, exactly
 * like every other command.
 */
#[AsCommand(name: 'doctor', description: 'Diagnose this tool\'s inputs and installation for a project.')]
final class DoctorCommand extends Command
{
    private const STATUS_WIDTH = 10;   // strlen('[skipped]') + 1
    private const ID_WIDTH = 25;       // strlen('project.php_declarations') + 1
    private const DETAIL_LABEL_WIDTH = 24;

    protected function configure(): void
    {
        PolicyOptions::configure($this);
        $this->addOption('rules-dir', null, InputOption::VALUE_REQUIRED, 'Advanced/testing: load rules from this directory instead of the bundled one.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = OutputWriter::errorOutput($output);

        // Phase 1 (D19): a mistake in doctor's own options. Rejected before any check runs, and the
        // only path where doctor prints no report at all — identical to every other command.
        try {
            $request = PolicyOptions::request($input);
            $rulesDirOption = self::stringOption($input, 'rules-dir');
        } catch (InputException $e) {
            OutputWriter::writeError($errorOutput, $e->getMessage());

            return ExitCode::INVALID_INPUT;
        }

        // Phase 2: everything from here on is a finding, not a caller mistake. DoctorRunner catches
        // every InputException / UnresolvablePolicyException / RuleDataException a check can raise and
        // turns it into a check result; a \Throwable escaping it is a bug in the tool, not a finding.
        try {
            $report = (new DoctorRunner())->run($request, $rulesDirOption);
        } catch (\Throwable $e) {
            OutputWriter::writeInternalError($errorOutput, $e->getMessage());

            return ExitCode::FAILURE;
        }

        if (PolicyOptions::json($input)) {
            $output->writeln(JsonPrinter::encode($report->toArray()));
        } else {
            foreach ($this->renderHuman($report) as $line) {
                $output->writeln($line);
            }
        }

        return $report->exitCode();
    }

    /** @return list<string> */
    private function renderHuman(DiagnosticReport $report): array
    {
        $lines = [];
        $lines[] = 'Doctor: ' . $report->status()->value;
        $lines[] = '';

        foreach ($report->checks as $check) {
            $lines[] = '  '
                . str_pad('[' . $check->status->value . ']', self::STATUS_WIDTH)
                . str_pad($check->id, self::ID_WIDTH)
                . $check->summary;
        }

        $lines[] = '';
        $lines[] = 'Details';
        foreach ($report->checks as $check) {
            array_push($lines, ...$this->renderDetailBlock($check));
        }

        return $lines;
    }

    /** @return list<string> */
    private function renderDetailBlock(DiagnosticCheck $check): array
    {
        $lines = [];
        $lines[] = '  ' . $check->id;

        // Detail labels are the JSON keys verbatim, in snake_case, deliberately unlike the spaced
        // labels the other commands use: an operator reading the human output must be able to map a
        // line straight to the JSON field with no translation table.
        foreach ($check->details as $label => $value) {
            $lines[] = '  ' . OutputWriter::field(self::DETAIL_LABEL_WIDTH, $label, OutputWriter::orDash($value));
        }

        return $lines;
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InputException(sprintf('--%s must be a string.', $name));
        }

        return $value;
    }
}

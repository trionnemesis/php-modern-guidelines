<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Command;

use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Exception\RuleDataException;
use ModernPhpGuidelines\Exception\UnknownRuleException;
use ModernPhpGuidelines\Exception\UnresolvablePolicyException;
use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use ModernPhpGuidelines\Policy\PolicyResolver;
use ModernPhpGuidelines\Rule\ApplicabilityEvaluator;
use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Support\JsonPrinter;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use ModernPhpGuidelines\Verification\ExecutableEvidenceNormalizer;
use ModernPhpGuidelines\Verification\PolicyFingerprint;
use ModernPhpGuidelines\Verification\VerificationAdapterRegistry;
use ModernPhpGuidelines\Verification\VerificationOrchestrator;
use ModernPhpGuidelines\Verification\VerificationReport;
use ModernPhpGuidelines\Verification\VerificationRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Explicit, policy-aware verification boundary. M3-A shipped only the contract and an unavailable
 * production placeholder; M3-B registers the real PHPCompatibility adapter, so this command now parses
 * a real analyzer's output and executes target-project analysis through it.
 */
#[AsCommand(name: 'verify', description: 'Collect policy-aware advisory evidence from an explicit adapter.')]
final class VerifyCommand extends Command
{
    private const LABEL_WIDTH = 23;

    public function __construct(private readonly VerificationAdapterRegistry $adapters)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        PolicyOptions::configure($this);
        $this
            ->addArgument('adapter', InputArgument::REQUIRED, 'Verification adapter id.')
            ->addOption(
                'executable',
                null,
                InputOption::VALUE_REQUIRED,
                'Already-installed executable selected for the adapter.',
            );
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConsoleException $e) {
            OutputWriter::writeError(OutputWriter::errorOutput($output), $e->getMessage());

            return ExitCode::INVALID_INPUT;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = OutputWriter::errorOutput($output);

        try {
            $adapterId = self::requiredStringArgument($input, 'adapter');
            if (preg_match('/^[a-z][a-z0-9_-]*$/', $adapterId) !== 1) {
                throw new InputException('adapter must be a lowercase verification adapter id.');
            }
            $executable = self::requiredStringOption($input, 'executable');
            $policy = (new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator()))
                ->resolve(PolicyOptions::request($input));

            $rules = (new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath())))
                ->loadDirectory(PackagePaths::rulesDirectory());

            $report = (new VerificationOrchestrator($this->adapters, new ApplicabilityEvaluator()))
                ->run($adapterId, new VerificationRequest($policy, $executable), $rules);

            if (PolicyOptions::json($input)) {
                $output->writeln(JsonPrinter::encode($report->toArray()), OutputInterface::OUTPUT_RAW);
            } else {
                foreach ($this->renderHuman($report) as $line) {
                    $output->writeln($line, OutputInterface::OUTPUT_RAW);
                }
            }

            return $report->exitCode;
        } catch (InputException $e) {
            OutputWriter::writeError($errorOutput, $e->getMessage());

            return ExitCode::INVALID_INPUT;
        } catch (UnknownRuleException $e) {
            OutputWriter::writeError($errorOutput, $e->getMessage());

            return ExitCode::UNKNOWN_RULE;
        } catch (UnresolvablePolicyException $e) {
            OutputWriter::writeError($errorOutput, $e->getMessage());

            return ExitCode::UNRESOLVABLE_POLICY;
        } catch (RuleDataException $e) {
            OutputWriter::writeError($errorOutput, $e->getMessage());

            return ExitCode::RULE_DATA_INVALID;
        } catch (\Throwable $e) {
            OutputWriter::writeInternalError($errorOutput, $e->getMessage());

            return ExitCode::FAILURE;
        }
    }

    /** @return list<string> */
    private function renderHuman(VerificationReport $report): array
    {
        $policy = $report->request->policy;
        $summary = $report->summary;

        $lines = [];
        $lines[] = sprintf('Verification: %s (exit %d)', $report->status->value, $report->exitCode);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'adapter', $report->adapterId);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'executable', $report->executable);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'tool version', OutputWriter::orDash($report->toolVersion));
        $lines[] = OutputWriter::field(
            self::LABEL_WIDTH,
            'policy fingerprint',
            PolicyFingerprint::forPolicy($policy),
        );
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'policy mode', $policy->mode->value);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'allowed minors', implode(', ', $policy->allowedMinors));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'feature ceiling', $policy->featureCeiling);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'lifecycle ceiling', $policy->lifecycleCeiling);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'projection', $report->projectionStatus->value);

        $lines[] = '';
        $lines[] = sprintf('Planned invocations: %d', count($report->plan->invocations));
        foreach ($report->plan->invocations as $invocation) {
            $lines[] = sprintf(
                '  %s  %s  PHP %s  cwd %s  timeout %dms',
                $invocation->id,
                $invocation->purpose->value,
                $invocation->policyMinors === [] ? '-' : implode(', ', $invocation->policyMinors),
                $invocation->workingDirectory->value,
                $invocation->timeoutMilliseconds,
            );
        }

        $lines[] = '';
        $lines[] = sprintf('Invocations: %d', $summary['invocation_count']);
        foreach ($report->invocations as $invocation) {
            $exit = $invocation->exitCode === null ? '-' : (string) $invocation->exitCode;
            $signal = $invocation->signal === null ? '-' : (string) $invocation->signal;
            $lines[] = sprintf(
                '  %s  %s  %s  exit %s  signal %s  PHP %s',
                $invocation->id,
                $invocation->purpose->value,
                $invocation->status->value,
                $exit,
                $signal,
                $invocation->policyMinors === [] ? '-' : implode(', ', $invocation->policyMinors),
            );
        }

        $lines[] = '';
        $lines[] = sprintf('Findings: %d', $summary['finding_count']);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'mapped findings', (string) $summary['mapped_finding_count']);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'unmapped findings', (string) $summary['unmapped_finding_count']);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'mappings', (string) $summary['mapping_count']);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'mapped rules', (string) $summary['mapped_rule_count']);

        if ($report->reason !== null) {
            $lines[] = '';
            $lines[] = 'Reason';
            $lines[] = '  ' . $report->reason->code;
            $lines[] = '  ' . $report->reason->message;
        }

        $lines[] = '';
        $lines[] = sprintf('Rule contexts: %d', count($report->ruleContexts));
        if ($report->ruleContexts === []) {
            $lines[] = '  (none)';
        } else {
            foreach ($report->ruleContexts as $context) {
                $lines[] = sprintf(
                    '  %s  [%s]',
                    $context->rule->id,
                    $context->applicability->status->value,
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Evidence';
        if ($report->findings === []) {
            $lines[] = '  (none)';
        } else {
            foreach ($report->findings as $finding) {
                $location = $finding->file ?? '-';
                if ($finding->line !== null) {
                    $location .= ':' . $finding->line;
                    if ($finding->column !== null) {
                        $location .= ':' . $finding->column;
                    }
                }
                $lines[] = sprintf(
                    '  [%s] %s  %s  %s',
                    $finding->mappingStatus->value,
                    $finding->externalRuleId,
                    $location,
                    $finding->message,
                );
                if ($finding->mappedRuleIds !== []) {
                    $lines[] = '    project rules: ' . implode(', ', $finding->mappedRuleIds);
                }
            }
        }

        return $lines;
    }

    /** @throws InputException */
    private static function requiredStringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        if (!is_string($value) || $value === '') {
            throw new InputException(sprintf('%s must be a non-empty string.', $name));
        }

        return $value;
    }

    /** @throws InputException */
    private static function requiredStringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value) || $value === '') {
            throw new InputException(sprintf('--%s is required and must be a non-empty string.', $name));
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InputException(sprintf('--%s must not contain control characters.', $name));
        }
        if ($name === 'executable' && !ExecutableEvidenceNormalizer::isValidSelection($value)) {
            throw new InputException(
                '--executable must be a stable path or PATH name and must not use a reserved <external> identity.',
            );
        }

        return $value;
    }
}

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
use ModernPhpGuidelines\Policy\PolicySource;
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Support\JsonPrinter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `resolve` — resolve the two-axis PHP compatibility policy for a project and print it as human
 * text or as a bare `policy.schema.json` instance.
 */
#[AsCommand(name: 'resolve', description: 'Resolve the two-axis PHP compatibility policy for a project.')]
final class ResolveCommand extends Command
{
    private const LABEL_WIDTH = 21;

    protected function configure(): void
    {
        PolicyOptions::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = OutputWriter::errorOutput($output);

        try {
            $request = PolicyOptions::request($input);
            $resolver = new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator());
            $policy = $resolver->resolve($request);

            if (PolicyOptions::json($input)) {
                $output->writeln(JsonPrinter::encode($policy->toArray()));
            } else {
                foreach ($this->renderHuman($policy) as $line) {
                    $output->writeln($line);
                }
            }

            return ExitCode::SUCCESS;
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
    private function renderHuman(ResolvedPolicy $policy): array
    {
        $lines = [];
        $lines[] = 'PHP policy';
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'mode', $policy->mode->value);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'project root', $policy->projectRoot);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'declared constraint', OutputWriter::orDash($policy->declaredConstraint));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'allowed minors', implode(', ', $policy->allowedMinors));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'feature ceiling', $policy->featureCeiling);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'lifecycle ceiling', $policy->lifecycleCeiling);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'platform override', OutputWriter::orDash($policy->platformOverride));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'observed runtime', OutputWriter::orDash($policy->observedRuntime));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'coverage', OutputWriter::renderCoverage($policy->coverage));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'confidence', $policy->confidence->value);

        $lines[] = '';
        $lines[] = 'Sources';
        array_push($lines, ...$this->renderSources($policy->sources));

        if ($policy->warnings !== []) {
            $lines[] = '';
            $lines[] = 'Warnings';
            foreach ($policy->warnings as $warning) {
                $lines[] = '  ' . $warning;
            }
        }

        return $lines;
    }

    /**
     * @param  list<PolicySource> $sources
     * @return list<string>
     */
    private function renderSources(array $sources): array
    {
        $typeWidth = 0;
        $pathWidth = 0;
        foreach ($sources as $source) {
            $typeWidth = max($typeWidth, strlen($source->type->value));
            $pathWidth = max($pathWidth, strlen(OutputWriter::orDash($source->path)));
        }

        $lines = [];
        foreach ($sources as $source) {
            $lines[] = '  '
                . str_pad($source->type->value, $typeWidth) . '  '
                . str_pad(OutputWriter::orDash($source->path), $pathWidth) . '  '
                . OutputWriter::orDash($source->value);
        }

        return $lines;
    }
}

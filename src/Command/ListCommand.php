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
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use ModernPhpGuidelines\Rule\ApplicabilityEvaluator;
use ModernPhpGuidelines\Rule\ApplicabilityResult;
use ModernPhpGuidelines\Rule\ApplicabilityStatus;
use ModernPhpGuidelines\Rule\Rule;
use ModernPhpGuidelines\Rule\RuleCategory;
use ModernPhpGuidelines\Rule\RuleKind;
use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Rule\RulePriority;
use ModernPhpGuidelines\Rule\RuleQuery;
use ModernPhpGuidelines\Rule\RuleRegistry;
use ModernPhpGuidelines\Support\JsonPrinter;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `list-rules` (alias `rules`) — list the bundled seed rules, filtered and annotated with their
 * applicability under the resolved policy.
 *
 * Registered as `list-rules`, never `list`: Symfony Console reserves the `list` command name for its
 * own command index. Overriding it would either shadow the built-in or be shadowed by it depending
 * on registration order.
 */
#[AsCommand(name: 'list-rules', description: 'List the bundled PHP rules, filtered by the resolved policy.', aliases: ['rules'])]
final class ListCommand extends Command
{
    private const STATUS_WIDTH = 33; // strlen('[forbidden_above_feature_ceiling]')
    private const KIND_WIDTH = 19;   // strlen('compatibility_guard')
    private const PRIORITY_WIDTH = 2;

    protected function configure(): void
    {
        PolicyOptions::configure($this);
        $this
            ->addOption('kind', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by rule kind. Repeatable.')
            ->addOption('category', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by rule category. Repeatable.')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by rule priority. Repeatable.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by applicability status. Repeatable.')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Filter by extension.')
            ->addOption('minor', null, InputOption::VALUE_REQUIRED, 'Keep only rules affecting this PHP minor.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include not_in_range rules.')
            ->addOption('rules-dir', null, InputOption::VALUE_REQUIRED, 'Advanced/testing: load rules from this directory instead of the bundled one.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = OutputWriter::errorOutput($output);

        try {
            $request = PolicyOptions::request($input);
            $resolver = new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator());
            $policy = $resolver->resolve($request);

            $rulesDir = self::stringOption($input, 'rules-dir') ?? PackagePaths::rulesDirectory();
            $loader = new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath()));
            $registry = $loader->loadDirectory($rulesDir);

            $kinds = self::sortEnums(self::parseEnumOption(self::stringListOption($input, 'kind'), RuleKind::class, 'kind'));
            $categories = self::sortEnums(self::parseEnumOption(self::stringListOption($input, 'category'), RuleCategory::class, 'category'));
            $priorities = self::sortEnums(self::parseEnumOption(self::stringListOption($input, 'priority'), RulePriority::class, 'priority'));
            $statuses = self::sortEnums(self::parseEnumOption(self::stringListOption($input, 'status'), ApplicabilityStatus::class, 'status'));
            $extension = self::stringOption($input, 'extension');
            $minor = self::stringOption($input, 'minor');
            $all = (bool) $input->getOption('all');

            $query = new RuleQuery($kinds, $categories, $priorities, $statuses, $extension, $minor, $all);
            $results = $registry->filter($query, $policy, new ApplicabilityEvaluator());

            if (PolicyOptions::json($input)) {
                $json = [
                    'output_version' => '1.0.0',
                    'policy' => $policy->toArray(),
                    'filters' => [
                        'kind' => array_map(static fn(RuleKind $kind): string => $kind->value, $kinds),
                        'category' => array_map(static fn(RuleCategory $category): string => $category->value, $categories),
                        'priority' => array_map(static fn(RulePriority $priority): string => $priority->value, $priorities),
                        'status' => array_map(static fn(ApplicabilityStatus $status): string => $status->value, $statuses),
                        'extension' => $extension,
                        'minor' => $minor,
                        'all' => $all,
                    ],
                    'total' => $registry->count(),
                    'rules' => array_map(
                        /** @param array{rule: Rule, applicability: ApplicabilityResult} $pair */
                        static fn(array $pair): array => self::ruleProjection($pair['rule'], $pair['applicability']),
                        $results,
                    ),
                ];
                $output->writeln(JsonPrinter::encode($json));
            } else {
                foreach ($this->renderHuman($policy, $registry, $results) as $line) {
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

    /**
     * @param  list<array{rule: Rule, applicability: ApplicabilityResult}> $results
     * @return list<string>
     */
    private function renderHuman(ResolvedPolicy $policy, RuleRegistry $registry, array $results): array
    {
        $lines = [];
        $lines[] = sprintf(
            'PHP policy: %s (allowed %s)',
            OutputWriter::policySummary($policy),
            implode(', ', $policy->allowedMinors),
        );
        $lines[] = sprintf('Rules: %d of %d shown', count($results), $registry->count());

        if ($results === []) {
            $lines[] = '  (no rules match the current policy and filters)';

            return $lines;
        }

        $lines[] = '';
        foreach ($results as $pair) {
            array_push($lines, ...$this->renderRuleRow($pair['rule'], $pair['applicability']));
        }

        return $lines;
    }

    /** @return list<string> */
    private function renderRuleRow(Rule $rule, ApplicabilityResult $applicability): array
    {
        $status = '[' . $applicability->status->value . ']';

        $line = '  '
            . str_pad($status, self::STATUS_WIDTH) . '  '
            . str_pad($rule->priority->value, self::PRIORITY_WIDTH) . '  '
            . str_pad($rule->kind->value, self::KIND_WIDTH) . '  '
            . $rule->id;

        return [$line, '      ' . $rule->title];
    }

    /** @return array<string, mixed> */
    private static function ruleProjection(Rule $rule, ApplicabilityResult $applicability): array
    {
        return [
            'id' => $rule->id,
            'title' => $rule->title,
            'summary' => $rule->summary,
            'category' => $rule->category->value,
            'kind' => $rule->kind->value,
            'priority' => $rule->priority->value,
            'introduced_in' => $rule->introducedIn,
            'deprecated_in' => $rule->deprecatedIn,
            'removed_in' => $rule->removedIn,
            'extension' => $rule->extension,
            'applicability' => $applicability->toArray(),
        ];
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

    /** @return list<string> */
    private static function stringListOption(InputInterface $input, string $name): array
    {
        $raw = $input->getOption($name);
        if (!is_array($raw)) {
            throw new InputException(sprintf('--%s must be an array.', $name));
        }

        $values = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                throw new InputException(sprintf('--%s values must be strings.', $name));
            }

            $values[] = $item;
        }

        return $values;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  list<string>    $rawValues
     * @param  class-string<T> $enumClass
     * @return list<T>
     */
    private static function parseEnumOption(array $rawValues, string $enumClass, string $optionName): array
    {
        $result = [];
        foreach ($rawValues as $raw) {
            $value = $enumClass::tryFrom($raw);
            if ($value === null) {
                $accepted = implode(', ', array_column($enumClass::cases(), 'value'));

                throw new InputException(sprintf(
                    'Unknown --%s value "%s". Expected one of: %s.',
                    $optionName,
                    $raw,
                    $accepted,
                ));
            }

            $result[] = $value;
        }

        return $result;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  list<T> $values
     * @return list<T>
     */
    private static function sortEnums(array $values): array
    {
        $sorted = $values;
        usort($sorted, static fn(\BackedEnum $a, \BackedEnum $b): int => strcmp((string) $a->value, (string) $b->value));

        return $sorted;
    }
}

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
use ModernPhpGuidelines\Rule\RuleKind;
use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Support\JsonPrinter;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `explain <rule-id>` — render a single rule plus its applicability under the resolved policy.
 */
#[AsCommand(name: 'explain', description: 'Explain a single rule and its applicability under the resolved policy.')]
final class ExplainCommand extends Command
{
    private const LABEL_WIDTH = 20;

    /**
     * The kind-aware reading of an applicability status (§4.3's 6 x 7 matrix). Keyed by
     * `RuleKind->value` then `ApplicabilityStatus->value` — enum instances cannot be array keys.
     * Untyped on purpose: typed class constants are PHP 8.3+.
     *
     * @var array<string, array<string, string>>
     */
    private const READINGS = [
        'feature' => [
            'applicable' => 'Available on every allowed minor — safe to emit.',
            'forbidden_above_feature_ceiling' => 'Exists on newer allowed minors but not on the feature ceiling — do not emit it.',
            'not_in_range' => 'Not available on any allowed minor — do not emit it.',
            'deprecated_in_range' => 'Available across the range, but deprecated on the newer allowed minors — do not emit it in new code.',
            'deprecated_across_range' => 'Deprecated on every allowed minor — do not emit it.',
            'removed_in_range' => 'Removed on the newer allowed minors — do not emit it.',
            'removed_across_range' => 'Removed on every allowed minor — do not emit it.',
        ],
        'modern_preference' => [
            'applicable' => 'Prefer this modern idiom throughout the range.',
            'forbidden_above_feature_ceiling' => 'The modern idiom is not safe for this range — keep the older form.',
            'not_in_range' => 'The modern idiom is unavailable on every allowed minor — keep the older form.',
            'deprecated_in_range' => 'The preferred idiom is itself deprecated on the newer allowed minors — do not migrate to it.',
            'deprecated_across_range' => 'The preferred idiom is deprecated on every allowed minor — this preference is obsolete.',
            'removed_in_range' => 'The preferred idiom is removed on the newer allowed minors — do not migrate to it.',
            'removed_across_range' => 'The preferred idiom is removed on every allowed minor — this preference is obsolete.',
        ],
        'deprecated' => [
            'applicable' => 'The deprecation is not reached by this range — no action is required yet.',
            'forbidden_above_feature_ceiling' => 'The construct is above the feature ceiling and its deprecation is not reached by this range.',
            'not_in_range' => 'Neither the construct nor its deprecation is reached by this range.',
            'deprecated_in_range' => 'Deprecated on part of the allowed range — plan the migration.',
            'deprecated_across_range' => 'Deprecated on every allowed minor — migrate away from it.',
            'removed_in_range' => 'Already removed on part of the allowed range — migrate now.',
            'removed_across_range' => 'Removed on every allowed minor — it cannot be used at all.',
        ],
        'removed' => [
            'applicable' => 'The removal is not reached by this range — no action is required yet.',
            'forbidden_above_feature_ceiling' => 'The construct is above the feature ceiling and its removal is not reached by this range.',
            'not_in_range' => 'Neither the construct nor its removal is reached by this range.',
            'deprecated_in_range' => 'Deprecated on part of the allowed range ahead of its removal — plan the migration.',
            'deprecated_across_range' => 'Deprecated on every allowed minor ahead of its removal — migrate away from it.',
            'removed_in_range' => 'Removed on part of the allowed range — code using it breaks on the newer allowed minors.',
            'removed_across_range' => 'Removed on every allowed minor — it cannot be used at all.',
        ],
        'compatibility_guard' => [
            'applicable' => 'The guard always applies.',
            'forbidden_above_feature_ceiling' => 'The guarded construct is above the feature ceiling — the guard forbids emitting it.',
            'not_in_range' => 'The guarded construct is outside the allowed range entirely — the guard forbids emitting it.',
            'deprecated_in_range' => 'The guarded construct is deprecated on part of the allowed range — the guard applies.',
            'deprecated_across_range' => 'The guarded construct is deprecated on every allowed minor — the guard applies.',
            'removed_in_range' => 'The guarded construct is removed on part of the allowed range — the guard applies.',
            'removed_across_range' => 'The guarded construct is removed on every allowed minor — the guard applies.',
        ],
        'behavior_change' => [
            'applicable' => 'The changed behavior applies uniformly across the range.',
            'forbidden_above_feature_ceiling' => 'The behavior differs across the allowed range — do not rely on either variant.',
            'not_in_range' => 'The change is not reached by this range.',
            'deprecated_in_range' => 'The changed behavior is deprecated on part of the allowed range.',
            'deprecated_across_range' => 'The changed behavior is deprecated on every allowed minor.',
            'removed_in_range' => 'The changed behavior is removed on part of the allowed range.',
            'removed_across_range' => 'The changed behavior is removed on every allowed minor.',
        ],
    ];

    /**
     * The kind-aware reading of an applicability status (§4.3's 6 x 7 matrix).
     *
     * PHPStan proves READINGS' 42 cells exhaustive from the literal const value plus the two enums'
     * literal ->value unions, so it flags the guard below as dead code (nullCoalesce.offset,
     * identical.alwaysFalse, throws.unusedType). It is kept anyway, deliberately: it is the guard
     * this method's own docs and WORK-ORDER.md §5.3 require so a future edit that drops a cell from
     * READINGS fails loudly with a named \LogicException instead of an undefined-index warning.
     *
     * @throws \LogicException when the cell is missing — a bug in READINGS, never user input.
     */
    // @phpstan-ignore throws.unusedType (PHPStan proves the throw below unreachable from READINGS' literal shape; @throws documents the real defensive contract)
    public static function reading(RuleKind $kind, ApplicabilityStatus $status): string
    {
        // @phpstan-ignore nullCoalesce.offset (see method docblock: intentional defensive guard)
        $reading = self::READINGS[$kind->value][$status->value] ?? null;
        // @phpstan-ignore identical.alwaysFalse (see method docblock: intentional defensive guard)
        if ($reading === null) {
            throw new \LogicException(sprintf(
                'No READINGS cell for kind "%s" and status "%s".',
                $kind->value,
                $status->value,
            ));
        }

        return $reading;
    }

    protected function configure(): void
    {
        PolicyOptions::configure($this);
        $this->addArgument('rule-id', InputArgument::REQUIRED, 'The rule id to explain.');
        $this->addOption('rules-dir', null, InputOption::VALUE_REQUIRED, 'Advanced/testing: load rules from this directory instead of the bundled one.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = OutputWriter::errorOutput($output);

        try {
            $request = PolicyOptions::request($input);
            $resolver = new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator());
            $policy = $resolver->resolve($request);

            $ruleIdArgument = $input->getArgument('rule-id');
            if (!is_string($ruleIdArgument)) {
                throw new InputException('rule-id must be a string.');
            }

            $rulesDir = self::stringOption($input, 'rules-dir') ?? PackagePaths::rulesDirectory();

            $loader = new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath()));
            $registry = $loader->loadDirectory($rulesDir);

            $rule = $registry->get($ruleIdArgument);
            $applicability = (new ApplicabilityEvaluator())->evaluate($rule, $policy);
            $readingText = self::reading($rule->kind, $applicability->status);

            if (PolicyOptions::json($input)) {
                $json = [
                    'output_version' => '1.0.0',
                    'policy' => $policy->toArray(),
                    'rule' => $rule->toArray(),
                    'applicability' => array_merge($applicability->toArray(), ['reading' => $readingText]),
                ];
                $output->writeln(JsonPrinter::encode($json));
            } else {
                foreach ($this->renderHuman($rule, $policy, $applicability, $readingText) as $line) {
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
    private function renderHuman(Rule $rule, ResolvedPolicy $policy, ApplicabilityResult $applicability, string $readingText): array
    {
        $lines = [];
        $lines[] = $rule->id . ' — ' . $rule->title;
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'category', $rule->category->value);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'kind', $rule->kind->value);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'priority', $rule->priority->value);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'introduced in', OutputWriter::orDash($rule->introducedIn));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'deprecated in', OutputWriter::orDash($rule->deprecatedIn));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'removed in', OutputWriter::orDash($rule->removedIn));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'extension', OutputWriter::orDash($rule->extension));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'behavior risk', $rule->behaviorChangeRisk->value);

        $lines[] = '';
        $lines[] = 'Applicability (policy: ' . OutputWriter::policySummary($policy) . ')';
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'status', $applicability->status->value);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'axis', $applicability->axis->value);
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'usable across range', $applicability->isUsableAcrossRange() ? 'yes' : 'no');
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'affected minors', implode(', ', $applicability->affectedMinors));
        $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'reading', $readingText);

        $lines[] = '';
        $lines[] = 'Guideline';
        $lines[] = '  ' . OutputWriter::wrap($rule->guideline);

        $lines[] = '';
        $lines[] = 'New code';
        $lines[] = '  ' . OutputWriter::wrap($rule->newCodePolicy);

        $lines[] = '';
        $lines[] = 'Existing code';
        $lines[] = '  ' . OutputWriter::wrap($rule->existingCodePolicy);

        $lines[] = '';
        $lines[] = 'Details';
        $lines[] = '  ' . OutputWriter::wrap($rule->details);

        $lines[] = '';
        $lines[] = 'Examples';
        array_push($lines, ...$this->renderExamples($rule));

        $verification = $rule->verification;
        if ($verification->phpcompatibility !== null || $verification->phpstan !== null || $verification->rector !== null) {
            $lines[] = '';
            $lines[] = 'Verification';
            $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'phpcompatibility', OutputWriter::orDash($verification->phpcompatibility));
            $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'phpstan', OutputWriter::orDash($verification->phpstan));
            $lines[] = OutputWriter::field(self::LABEL_WIDTH, 'rector', OutputWriter::orDash($verification->rector));
        }

        $lines[] = '';
        $lines[] = 'Sources';
        array_push($lines, ...$this->renderSources($rule));

        if ($rule->notes !== []) {
            $lines[] = '';
            $lines[] = 'Notes';
            foreach ($rule->notes as $note) {
                $lines[] = '  - ' . $note;
            }
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

    /** @return list<string> */
    private function renderExamples(Rule $rule): array
    {
        $lines = [];
        foreach ($rule->examples as $index => $example) {
            $number = $index + 1;
            if ($example->noAutomaticReplacement) {
                $lines[] = '  ' . $number . '. no automatic replacement';

                continue;
            }

            $lines[] = '  ' . $number . '. before:';
            foreach ($example->before ?? [] as $line) {
                $lines[] = '       ' . $line;
            }

            $lines[] = '     after:';
            foreach ($example->after ?? [] as $line) {
                $lines[] = '       ' . $line;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function renderSources(Rule $rule): array
    {
        $typeWidth = 0;
        $urlWidth = 0;
        foreach ($rule->sources as $source) {
            $typeWidth = max($typeWidth, strlen($source->type));
            $urlWidth = max($urlWidth, strlen($source->url));
        }

        $lines = [];
        foreach ($rule->sources as $source) {
            $lines[] = '  '
                . str_pad($source->type, $typeWidth) . '  '
                . str_pad($source->url, $urlWidth) . '  '
                . $source->checkedAt;
        }

        return $lines;
    }
}

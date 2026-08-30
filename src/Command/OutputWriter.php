<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Command;

use ModernPhpGuidelines\Policy\Coverage;
use ModernPhpGuidelines\Policy\ResolvedPolicy;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * stdout/stderr split, deterministic label padding, and error rendering shared by all three
 * commands. Every helper here is a pure string builder or a thin write — no command holds any
 * state a second call here would need.
 */
final class OutputWriter
{
    private function __construct() {}

    /** `$output->getErrorOutput()` when available, otherwise `$output` itself. */
    public static function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public static function writeError(OutputInterface $errorOutput, string $message): void
    {
        $errorOutput->writeln('Error: ' . $message);
    }

    public static function writeInternalError(OutputInterface $errorOutput, string $message): void
    {
        $errorOutput->writeln('Error: internal error: ' . $message);
    }

    /** A two-space-indented, label-padded `key value` line. */
    public static function field(int $labelWidth, string $label, string $value): string
    {
        return '  ' . str_pad($label, $labelWidth) . $value;
    }

    public static function orDash(?string $value): string
    {
        return $value ?? '-';
    }

    /** `<status> (known <min>-<max>[, open upper bound])`. */
    public static function renderCoverage(Coverage $coverage): string
    {
        $range = sprintf('known %s-%s', $coverage->knownMin, $coverage->knownMax);
        if ($coverage->openUpperBound) {
            $range .= ', open upper bound';
        }

        return sprintf('%s (%s)', $coverage->status->value, $range);
    }

    /** `<mode>, feature ceiling <F>, lifecycle ceiling <L>` — shared by list-rules and explain headers. */
    public static function policySummary(ResolvedPolicy $policy): string
    {
        return sprintf(
            '%s, feature ceiling %s, lifecycle ceiling %s',
            $policy->mode->value,
            $policy->featureCeiling,
            $policy->lifecycleCeiling,
        );
    }

    /** Deterministic wrapping at 96 columns, on whitespace, with a two-space continuation indent. */
    public static function wrap(string $text): string
    {
        return wordwrap($text, 96, "\n  ", false);
    }
}

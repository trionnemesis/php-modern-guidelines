<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Command;

use ModernPhpGuidelines\Command\ExplainCommand;
use ModernPhpGuidelines\Rule\ApplicabilityStatus;
use ModernPhpGuidelines\Rule\RuleKind;
use PHPUnit\Framework\TestCase;

/**
 * All 6 x 7 = 42 READINGS cells exist and are non-empty, exercised through the public
 * `ExplainCommand::reading()` accessor — no reflection (WORK-ORDER.md §5.3).
 */
final class ExplainReadingsTest extends TestCase
{
    public function testEveryKindStatusCellHasANonEmptyReading(): void
    {
        $seen = 0;

        foreach (RuleKind::cases() as $kind) {
            foreach (ApplicabilityStatus::cases() as $status) {
                $reading = ExplainCommand::reading($kind, $status);
                self::assertNotSame(
                    '',
                    $reading,
                    sprintf('Empty reading for kind "%s" and status "%s".', $kind->value, $status->value),
                );
                ++$seen;
            }
        }

        self::assertSame(42, $seen, 'Expected exactly 6 kinds x 7 statuses = 42 cells.');
    }

    public function testReadingsAreDistinctPerKindAndStatus(): void
    {
        // A sanity check against a copy-paste bug that fills every cell with the same sentence:
        // each kind's seven readings must not all collapse into a single string.
        foreach (RuleKind::cases() as $kind) {
            $readings = [];
            foreach (ApplicabilityStatus::cases() as $status) {
                $readings[] = ExplainCommand::reading($kind, $status);
            }

            self::assertGreaterThan(
                1,
                count(array_unique($readings)),
                sprintf('All readings for kind "%s" are identical.', $kind->value),
            );
        }
    }
}

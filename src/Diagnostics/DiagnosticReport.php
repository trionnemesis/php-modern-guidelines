<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Diagnostics;

use ModernPhpGuidelines\Command\ExitCode;

/**
 * The complete, always-full `doctor` report: every check DoctorRunner::CHECK_IDS names, in that order,
 * whatever each one found. `doctor` prints this same shape whether it exits 0 or not (WORK-ORDER.md
 * D19) — the finding is a field inside the report, never a partial document.
 */
final class DiagnosticReport
{
    public const OUTPUT_VERSION = '1.0.0';

    /**
     * @param list<DiagnosticCheck> $checks in the fixed order of §5.3, non-empty
     *
     * @throws \LogicException when $checks is empty or its ids are not exactly
     *                          DoctorRunner::CHECK_IDS in that order — a bug in DoctorRunner, never
     *                          user input, and what stops a later edit from silently dropping a check.
     */
    public function __construct(public readonly array $checks)
    {
        if ($this->checks === []) {
            throw new \LogicException('DiagnosticReport::$checks must not be empty.');
        }

        $ids = array_map(static fn(DiagnosticCheck $check): string => $check->id, $this->checks);
        if ($ids !== DoctorRunner::CHECK_IDS) {
            throw new \LogicException(sprintf(
                'DiagnosticReport::$checks ids must be exactly DoctorRunner::CHECK_IDS in order; got [%s].',
                implode(', ', $ids),
            ));
        }
    }

    /** Worst status present: fail > warn > skipped > ok. */
    public function status(): CheckStatus
    {
        $rank = [
            CheckStatus::Ok->value => 0,
            CheckStatus::Skipped->value => 1,
            CheckStatus::Warn->value => 2,
            CheckStatus::Fail->value => 3,
        ];

        $worst = CheckStatus::Ok;
        $worstRank = 0;
        foreach ($this->checks as $check) {
            $checkRank = $rank[$check->status->value];
            if ($checkRank > $worstRank) {
                $worstRank = $checkRank;
                $worst = $check->status;
            }
        }

        return $worst;
    }

    /** The exitCode of the FIRST check whose status is Fail; ExitCode::SUCCESS when none failed. */
    public function exitCode(): int
    {
        foreach ($this->checks as $check) {
            if ($check->status === CheckStatus::Fail) {
                return $check->exitCode;
            }
        }

        return ExitCode::SUCCESS;
    }

    /**
     * @return array{
     *     output_version: string,
     *     status: string,
     *     exit_code: int,
     *     checks: list<array{id: string, status: string, summary: string, details: array<string, string|null>}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'output_version' => self::OUTPUT_VERSION,
            'status' => $this->status()->value,
            'exit_code' => $this->exitCode(),
            'checks' => array_map(static fn(DiagnosticCheck $check): array => $check->toArray(), $this->checks),
        ];
    }
}

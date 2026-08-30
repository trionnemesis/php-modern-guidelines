<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Diagnostics;

use ModernPhpGuidelines\Command\ExitCode;

/**
 * One `doctor` check result: a status, a pinned path-free summary (WORK-ORDER.md §5.4a), and a fixed,
 * insertion-ordered detail-key set (§5.4). Every detail value is `string|null`; there is no `bool`, no
 * `int` and no nested array in a detail value.
 */
final class DiagnosticCheck
{
    /**
     * @param array<string, string|null> $details fixed key set per check id (§5.4), insertion-ordered
     * @param int                        $exitCode ExitCode::SUCCESS unless $status is CheckStatus::Fail
     */
    public function __construct(
        public readonly string $id,
        public readonly CheckStatus $status,
        public readonly string $summary,
        public readonly array $details,
        public readonly int $exitCode = ExitCode::SUCCESS,
    ) {}

    /** @return array{id: string, status: string, summary: string, details: array<string, string|null>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'summary' => $this->summary,
            // Every check id in DoctorRunner::CHECK_IDS declares at least two detail keys (§5.4), so
            // this can never encode as a JSON array ([]) instead of an object ({}).
            'details' => $this->details,
        ];
    }
}

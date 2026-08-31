<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification;

final class VerificationFinding
{
    /**
     * @param list<string> $invocationIds
     * @param list<string> $mappedRuleIds
     */
    public function __construct(
        public readonly EvidenceClass $evidenceClass,
        public readonly array $invocationIds,
        public readonly string $externalRuleId,
        public readonly ?string $type,
        public readonly ?string $severity,
        public readonly string $message,
        public readonly ?string $file,
        public readonly ?int $line,
        public readonly ?int $column,
        public readonly ?string $proposedDiff,
        public readonly MappingStatus $mappingStatus,
        public readonly array $mappedRuleIds,
    ) {
        if ($this->invocationIds === [] || array_values(array_unique($this->invocationIds)) !== $this->invocationIds) {
            throw new \LogicException('A verification finding must reference at least one unique invocation id.');
        }

        foreach ($this->invocationIds as $invocationId) {
            if (preg_match('/^verification-[1-9][0-9]*$/', $invocationId) !== 1) {
                throw new \LogicException('Verification finding invocation ids must use the verification-N form.');
            }
        }

        $sortedInvocationIds = $this->invocationIds;
        sort($sortedInvocationIds, SORT_NATURAL);
        if ($sortedInvocationIds !== $this->invocationIds) {
            throw new \LogicException('Verification finding invocation ids must be naturally ordered.');
        }

        if ($this->externalRuleId === '' || $this->message === '') {
            throw new \LogicException('A verification finding must preserve a non-empty external rule id and message.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $this->externalRuleId . $this->message) === 1) {
            throw new \LogicException('Verification finding identifiers and messages must not contain control characters.');
        }

        if ($this->type === '' || $this->severity === ''
            || ($this->type !== null && preg_match('/[\x00-\x1F\x7F]/', $this->type) === 1)
            || ($this->severity !== null && preg_match('/[\x00-\x1F\x7F]/', $this->severity) === 1)) {
            throw new \LogicException(
                'Verification finding type and severity must be non-empty and free of control characters when present.',
            );
        }

        if ($this->file !== null && (
            $this->file === ''
            || str_starts_with($this->file, '/')
            || preg_match('/^[A-Za-z]:/', $this->file) === 1
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]+:/', $this->file) === 1
            || str_contains($this->file, '\\')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $this->file) === 1
            || str_contains($this->file, '//')
            || str_ends_with($this->file, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $this->file) === 1
        )) {
            throw new \LogicException('A verification finding file must be a normalized project-relative path.');
        }

        if (($this->line !== null && $this->line < 1) || ($this->column !== null && $this->column < 1)) {
            throw new \LogicException('Verification finding line and column values must be positive when present.');
        }

        if ($this->evidenceClass === EvidenceClass::ProposedTransformation) {
            if ($this->proposedDiff === null || $this->proposedDiff === '') {
                throw new \LogicException('A proposed transformation finding must carry a proposed diff.');
            }
        } elseif ($this->proposedDiff !== null) {
            throw new \LogicException('Only proposed transformation findings may carry a proposed diff.');
        }

        $sortedRuleIds = $this->mappedRuleIds;
        sort($sortedRuleIds, SORT_STRING);
        if ($sortedRuleIds !== $this->mappedRuleIds || array_values(array_unique($sortedRuleIds)) !== $sortedRuleIds) {
            throw new \LogicException('Mapped internal rule ids must be sorted and unique.');
        }

        if ($this->mappingStatus === MappingStatus::Mapped && $this->mappedRuleIds === []) {
            throw new \LogicException('A mapped finding must name at least one internal rule id.');
        }

        if ($this->mappingStatus === MappingStatus::Unmapped && $this->mappedRuleIds !== []) {
            throw new \LogicException('An unmapped finding must not name an internal rule id.');
        }
    }

    /**
     * @return array{
     *     evidence_class: string,
     *     invocation_ids: list<string>,
     *     external_rule_id: string,
     *     type: string|null,
     *     severity: string|null,
     *     message: string,
     *     file: string|null,
     *     line: int|null,
     *     column: int|null,
     *     proposed_diff: string|null,
     *     mapping_status: string,
     *     mapped_rule_ids: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'evidence_class' => $this->evidenceClass->value,
            'invocation_ids' => $this->invocationIds,
            'external_rule_id' => $this->externalRuleId,
            'type' => $this->type,
            'severity' => $this->severity,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'column' => $this->column,
            'proposed_diff' => $this->proposedDiff,
            'mapping_status' => $this->mappingStatus->value,
            'mapped_rule_ids' => $this->mappedRuleIds,
        ];
    }

    /** @return array{int, string, int, int, int, int, string, string, string, string, string, string, string, string, string} */
    public function sortKey(): array
    {
        return [
            $this->file === null ? 1 : 0,
            $this->file ?? '',
            $this->line === null ? 1 : 0,
            $this->line ?? 0,
            $this->column === null ? 1 : 0,
            $this->column ?? 0,
            $this->evidenceClass->value,
            $this->externalRuleId,
            $this->type ?? '',
            $this->severity ?? '',
            $this->message,
            implode("\0", $this->invocationIds),
            $this->mappingStatus->value,
            implode("\0", $this->mappedRuleIds),
            $this->proposedDiff ?? '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Verification\Adapter;

use ModernPhpGuidelines\Php\KnownPhpMinors;
use ModernPhpGuidelines\Policy\CoverageStatus;
use ModernPhpGuidelines\Policy\ResolutionMode;
use ModernPhpGuidelines\Verification\AdapterOutcome;
use ModernPhpGuidelines\Verification\AdapterPlan;
use ModernPhpGuidelines\Verification\AdapterResult;
use ModernPhpGuidelines\Verification\EvidenceClass;
use ModernPhpGuidelines\Verification\ExecutableLocator;
use ModernPhpGuidelines\Verification\InvocationPurpose;
use ModernPhpGuidelines\Verification\MappingStatus;
use ModernPhpGuidelines\Verification\PlannedVerificationInvocation;
use ModernPhpGuidelines\Verification\Process\ProcessState;
use ModernPhpGuidelines\Verification\ProjectionStatus;
use ModernPhpGuidelines\Verification\ProjectPathNormalizer;
use ModernPhpGuidelines\Verification\VerificationAdapter;
use ModernPhpGuidelines\Verification\VerificationExecutor;
use ModernPhpGuidelines\Verification\VerificationFinding;
use ModernPhpGuidelines\Verification\VerificationInvocation;
use ModernPhpGuidelines\Verification\VerificationReason;
use ModernPhpGuidelines\Verification\VerificationRequest;

/**
 * The real PHPCompatibility adapter: exact-projection planning against PHP_CodeSniffer's
 * `--runtime-set testVersion` contract, and truthful, deterministic verification through the
 * ADR-008-mandated VerificationExecutor. No execution seam exists for a test double anywhere on this
 * path: ExecutableLocator and VerificationExecutor are both final with no interface, so both are
 * constructed here, in the constructor body, rather than accepted as parameters.
 */
final class PhpCompatibilityAdapter implements VerificationAdapter
{
    public const ADAPTER_ID = 'phpcompatibility';
    public const STANDARD = 'PHPCompatibility';

    /**
     * Committed exact sniff-id -> internal rule-id mapping. Keys are PHPCompatibility `source` values
     * verified against squizlabs/php_codesniffer 3.13.6 + phpcompatibility/php-compatibility
     * 10.0.0-alpha2. An unknown key stays unmapped; nothing here is inferred from message text.
     *
     * @var array<string, list<string>>
     */
    private const SNIFF_RULE_MAP = [
        'PHPCompatibility.Classes.NewTypedConstants.Found' => ['language.typed_class_constants'],
        'PHPCompatibility.FunctionDeclarations.RemovedImplicitlyNullableParam.Deprecated' => [
            'language.implicitly_nullable_parameter_types',
        ],
        'PHPCompatibility.FunctionUse.NewFunctions.array_allFound' => ['core.array_find_functions'],
        'PHPCompatibility.FunctionUse.NewFunctions.array_anyFound' => ['core.array_find_functions'],
        'PHPCompatibility.FunctionUse.NewFunctions.array_findFound' => ['core.array_find_functions'],
        'PHPCompatibility.FunctionUse.NewFunctions.array_find_keyFound' => ['core.array_find_functions'],
        'PHPCompatibility.FunctionUse.NewFunctions.array_firstFound' => ['core.array_first_last'],
        'PHPCompatibility.FunctionUse.NewFunctions.array_lastFound' => ['core.array_first_last'],
        'PHPCompatibility.FunctionUse.NewFunctions.json_validateFound' => ['core.json_validate'],
        'PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated' => ['extension.curl_close'],
        'PHPCompatibility.IniDirectives.RemovedIniDirectives.mysqli_reconnectRemoved' => [
            'extension.mysqli_driver_reconnect',
        ],
        'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax' => [
            'language.dollar_brace_string_interpolation',
        ],
        'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax' => [
            'language.dollar_brace_string_interpolation',
        ],
    ];

    private readonly ExecutableLocator $locator;
    private readonly VerificationExecutor $executor;

    public function __construct(
        private readonly int $timeoutMilliseconds =
            PlannedVerificationInvocation::DEFAULT_TIMEOUT_MILLISECONDS,
    ) {
        $this->locator = new ExecutableLocator();
        $this->executor = new VerificationExecutor();
    }

    public function id(): string
    {
        return self::ADAPTER_ID;
    }

    public function plan(VerificationRequest $request): AdapterPlan
    {
        $policy = $request->policy;

        // Rule 1: locate first. The located path proves availability only — it is discarded here; the
        // executor locates again itself on every call. Checking policy projection before availability
        // would report an unprojectable policy as `exit 9` even when the executable is missing, which
        // the PHAR smoke test forbids.
        if ($this->locator->locate($request->executable, $policy->projectRoot) === null) {
            return $this->notEvaluated('The selected executable is not available or executable.');
        }

        // Rule 2: coverage/mode precondition, mirroring PolicyProjectionValidator::assertExact() exactly
        // so the core validator can never fire a LogicException for our plan. single-target and
        // runtime-observed always carry exactly one allowed minor, so no coverage guard applies to them.
        if ($policy->mode === ResolutionMode::RangeSafe
            && ($policy->coverage->status !== CoverageStatus::Complete || $policy->coverage->openUpperBound)) {
            return $this->unsupported(sprintf(
                'The resolved range-safe policy cannot be projected exactly: known coverage is %s and the '
                . 'upper bound is %s.',
                $policy->coverage->status->value,
                $policy->coverage->openUpperBound ? 'open' : 'closed',
            ));
        }

        // Rule 3: known-minor precondition. Reachable only through runtime-observed on a runtime outside
        // 8.2-8.5; PolicyResolver cannot otherwise emit an unknown minor.
        foreach ($policy->allowedMinors as $minor) {
            if (!KnownPhpMinors::contains($minor)) {
                return $this->unsupported(sprintf(
                    'The resolved policy names PHP %s, which is outside the PHP minors this tool knows '
                    . '(%s-%s), so it cannot be projected exactly.',
                    $minor,
                    KnownPhpMinors::KNOWN_MIN,
                    KnownPhpMinors::KNOWN_MAX,
                ));
            }
        }

        // Rule 4: contiguity precondition. The indexes of $policy->allowedMinors inside
        // KnownPhpMinors::all() must be consecutive ascending integers, or a PHPCompatibility
        // testVersion range would assert compatibility with an excluded minor too (a widening ADR-008
        // forbids), even though the core validator's union-of-planned-minors check would not catch it.
        $known = KnownPhpMinors::all();
        $indexes = [];
        foreach ($policy->allowedMinors as $minor) {
            $index = array_search($minor, $known, true);
            if ($index === false) {
                // Unreachable: rule 3 already confirmed every minor is known.
                throw new \LogicException(sprintf(
                    'PHP %s passed the known-minor check but is missing from KnownPhpMinors::all().',
                    $minor,
                ));
            }
            $indexes[] = $index;
        }

        for ($i = 1, $count = count($indexes); $i < $count; ++$i) {
            if ($indexes[$i] === $indexes[$i - 1] + 1) {
                continue;
            }

            $missing = array_values(array_diff(
                array_slice($known, $indexes[0], $indexes[$count - 1] - $indexes[0] + 1),
                $policy->allowedMinors,
            ));

            return $this->unsupported(sprintf(
                'The resolved policy allows PHP %s but excludes PHP %s, and a PHPCompatibility testVersion '
                . 'range cannot express that gap.',
                implode(', ', $policy->allowedMinors),
                implode(', ', $missing),
            ));
        }

        return new AdapterPlan(
            ProjectionStatus::Supported,
            $this->plannedInvocations($request, $policy->allowedMinors),
            null,
        );
    }

    public function verify(VerificationRequest $request, AdapterPlan $plan): AdapterResult
    {
        $invocations = $plan->invocations;
        if (count($invocations) !== 3) {
            throw new \LogicException('A PHPCompatibility adapter plan must contain exactly three invocations.');
        }

        /** @var list<VerificationInvocation> $executed */
        $executed = [];
        $toolVersion = null;

        foreach ($invocations as $stage => $planned) {
            $execution = $this->executor->execute($request, $planned);
            $executed[] = $execution->invocation;

            $stateReason = $this->stateFailureReason($execution->invocation->status);
            if ($stateReason !== null) {
                return $this->failed($executed, $toolVersion, $stateReason);
            }

            $exitCode = $execution->invocation->exitCode;

            if ($stage === 0) {
                if ($exitCode !== 0) {
                    return $this->failed($executed, $toolVersion, new VerificationReason(
                        VerificationReason::PROCESS_EXIT_FAILED,
                        'The PHP_CodeSniffer version probe exited with a non-zero status.',
                    ));
                }

                $toolVersion = $this->parseToolVersion($execution->stdout);
                if ($toolVersion === null) {
                    return $this->unavailable($executed, $toolVersion, new VerificationReason(
                        VerificationReason::CAPABILITY_UNAVAILABLE,
                        'The selected executable did not report a PHP_CodeSniffer version.',
                    ));
                }

                continue;
            }

            if ($stage === 1) {
                if ($exitCode !== 0) {
                    return $this->failed($executed, $toolVersion, new VerificationReason(
                        VerificationReason::PROCESS_EXIT_FAILED,
                        'The PHP_CodeSniffer standards probe exited with a non-zero status.',
                    ));
                }

                if (!$this->standardIsRegistered($execution->stdout)) {
                    return $this->unavailable($executed, $toolVersion, new VerificationReason(
                        VerificationReason::CAPABILITY_UNAVAILABLE,
                        'The selected PHP_CodeSniffer installation does not register the PHPCompatibility '
                        . 'standard.',
                    ));
                }

                continue;
            }

            // Stage 2: the analysis invocation. phpcs exits 0 (clean), 1 (warnings only) or 2 (errors);
            // anything else means the analysis itself did not complete (e.g. a processing error whose
            // stdout is plain text, not JSON).
            if (!in_array($exitCode, [0, 1, 2], true)) {
                return $this->failed($executed, $toolVersion, new VerificationReason(
                    VerificationReason::PROCESS_EXIT_FAILED,
                    'PHP_CodeSniffer exited with a status that does not indicate a completed analysis.',
                ));
            }

            try {
                $findings = $this->normalizeReport(
                    $execution->stdout,
                    new ProjectPathNormalizer($request->policy->projectRoot),
                    $planned->id,
                );
            } catch (\DomainException $e) {
                return $this->failed($executed, $toolVersion, new VerificationReason(
                    VerificationReason::OUTPUT_INVALID,
                    $e->getMessage(),
                ));
            }

            if ($findings === [] && $exitCode !== 0) {
                return $this->failed($executed, $toolVersion, new VerificationReason(
                    VerificationReason::OUTPUT_INVALID,
                    'PHP_CodeSniffer reported a non-zero analysis status without publishing any finding.',
                ));
            }

            return $this->completed($executed, $toolVersion, $findings);
        }

        throw new \LogicException('A PHPCompatibility adapter plan must reach a terminal verification outcome.');
    }

    /**
     * @param  list<string>                        $minors
     * @return list<PlannedVerificationInvocation>
     */
    private function plannedInvocations(VerificationRequest $request, array $minors): array
    {
        $executable = $request->evidenceExecutable();

        return [
            new PlannedVerificationInvocation(
                'verification-1',
                [],
                $executable,
                ['--version'],
                InvocationPurpose::ToolProbe,
                timeoutMilliseconds: $this->timeoutMilliseconds,
            ),
            new PlannedVerificationInvocation(
                'verification-2',
                [],
                $executable,
                ['-i'],
                InvocationPurpose::ToolProbe,
                timeoutMilliseconds: $this->timeoutMilliseconds,
            ),
            new PlannedVerificationInvocation(
                'verification-3',
                $minors,
                $executable,
                [
                    '--standard=' . self::STANDARD,
                    '--runtime-set',
                    'testVersion',
                    $this->projectionFor($minors),
                    '--report=json',
                    '--basepath=.',
                    '--parallel=1',
                    '--extensions=php',
                    '--severity=1',
                    '--no-cache',
                    '-q',
                    '.',
                ],
                InvocationPurpose::Analysis,
                timeoutMilliseconds: $this->timeoutMilliseconds,
            ),
        ];
    }

    /** @param list<string> $minors ascending, non-empty */
    private function projectionFor(array $minors): string
    {
        if (count($minors) === 1) {
            return $minors[0];
        }

        return $minors[0] . '-' . $minors[count($minors) - 1];
    }

    private function notEvaluated(string $message): AdapterPlan
    {
        return new AdapterPlan(
            ProjectionStatus::NotEvaluated,
            [],
            new VerificationReason(VerificationReason::EXECUTABLE_UNAVAILABLE, $message),
        );
    }

    private function unsupported(string $message): AdapterPlan
    {
        return new AdapterPlan(
            ProjectionStatus::Unsupported,
            [],
            new VerificationReason(VerificationReason::POLICY_PROJECTION_UNSUPPORTED, $message),
        );
    }

    /** One shared mapping, used identically at every stage: a non-Exited state always fails the same way. */
    private function stateFailureReason(ProcessState $state): ?VerificationReason
    {
        return match ($state) {
            ProcessState::StartFailed => new VerificationReason(
                VerificationReason::PROCESS_START_FAILED,
                'The selected PHP_CodeSniffer executable could not be started.',
            ),
            ProcessState::TimedOut => new VerificationReason(
                VerificationReason::PROCESS_TIMED_OUT,
                'PHP_CodeSniffer did not finish within the planned timeout.',
            ),
            ProcessState::Signaled => new VerificationReason(
                VerificationReason::PROCESS_SIGNALED,
                'PHP_CodeSniffer was terminated by a signal.',
            ),
            ProcessState::OutputLimitExceeded => new VerificationReason(
                VerificationReason::OUTPUT_LIMIT_EXCEEDED,
                'PHP_CodeSniffer exceeded the bounded output capture.',
            ),
            ProcessState::Exited => null,
        };
    }

    private function parseToolVersion(string $stdout): ?string
    {
        $newline = strpos($stdout, "\n");
        $line = rtrim($newline === false ? $stdout : substr($stdout, 0, $newline), "\r");

        if (preg_match('/\APHP_CodeSniffer version (\d+\.\d+\.\d+[0-9A-Za-z.+-]*)(?: |\z)/', $line, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function standardIsRegistered(string $stdout): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])PHPCompatibility(?![A-Za-z0-9_])/', $stdout) === 1;
    }

    /** @return list<VerificationFinding> */
    private function normalizeReport(string $stdout, ProjectPathNormalizer $normalizer, string $invocationId): array
    {
        try {
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('PHP_CodeSniffer did not produce a parseable JSON report.');
        }

        if (!is_array($decoded)) {
            throw new \DomainException('The PHP_CodeSniffer JSON report does not have the expected structure.');
        }

        $totals = $decoded['totals'] ?? null;
        if (!is_array($totals)
            || !self::isNonNegativeInt($totals['errors'] ?? null)
            || !self::isNonNegativeInt($totals['warnings'] ?? null)
            || !self::isNonNegativeInt($totals['fixable'] ?? null)) {
            throw new \DomainException('The PHP_CodeSniffer JSON report does not have the expected structure.');
        }

        $files = $decoded['files'] ?? null;
        if (!is_array($files)) {
            throw new \DomainException('The PHP_CodeSniffer JSON report does not have the expected structure.');
        }

        /** @var list<VerificationFinding> $findings */
        $findings = [];

        foreach ($files as $fileKey => $file) {
            if (!is_string($fileKey) || $fileKey === '') {
                throw new \DomainException('The PHP_CodeSniffer JSON report does not have the expected structure.');
            }

            if (!is_array($file)) {
                throw new \DomainException('The PHP_CodeSniffer JSON report does not have the expected structure.');
            }

            $fileErrors = $file['errors'] ?? null;
            if (!is_int($fileErrors) || $fileErrors < 0) {
                throw new \DomainException('The PHP_CodeSniffer JSON report does not have the expected structure.');
            }

            $fileWarnings = $file['warnings'] ?? null;
            if (!is_int($fileWarnings) || $fileWarnings < 0) {
                throw new \DomainException('The PHP_CodeSniffer JSON report does not have the expected structure.');
            }

            $messages = $file['messages'] ?? null;
            if (!is_array($messages) || !array_is_list($messages)) {
                throw new \DomainException('The PHP_CodeSniffer JSON report does not have the expected structure.');
            }

            // Fail closed on control bytes ProjectPathNormalizer does not itself reject (it rejects NUL,
            // URI schemes, drive-relative forms and escaping paths, but not other control bytes), so a
            // control-byte file key never reaches VerificationFinding's own, uncatchable, invariant check.
            if (preg_match('/[\x00-\x1F\x7F]/', $fileKey) === 1) {
                throw new \DomainException(
                    'The PHP_CodeSniffer JSON report named a file that cannot be expressed relative to the '
                    . 'project root.',
                );
            }

            try {
                $relativeFile = $normalizer->normalize($fileKey);
            } catch (\InvalidArgumentException) {
                throw new \DomainException(
                    'The PHP_CodeSniffer JSON report named a file that cannot be expressed relative to the '
                    . 'project root.',
                );
            }

            $parsedCount = 0;
            foreach ($messages as $message) {
                $findings[] = $this->normalizeMessage($message, $relativeFile, $invocationId);
                ++$parsedCount;
            }

            // Runs before dedupe: duplicates are real messages and count toward the analyzer's totals.
            if ($fileErrors + $fileWarnings !== $parsedCount) {
                throw new \DomainException(
                    'The PHP_CodeSniffer JSON report is internally inconsistent: its per-file counts do not '
                    . 'match the messages it published.',
                );
            }
        }

        return self::dedupe($findings);
    }

    private function normalizeMessage(mixed $message, string $file, string $invocationId): VerificationFinding
    {
        if (!is_array($message)) {
            throw new \DomainException(
                'The PHP_CodeSniffer JSON report contains a message without a usable identifier or text.',
            );
        }

        $source = $message['source'] ?? null;
        if (!is_string($source) || $source === '' || preg_match('/[\x00-\x1F\x7F]/', $source) === 1) {
            throw new \DomainException(
                'The PHP_CodeSniffer JSON report contains a message without a usable identifier or text.',
            );
        }

        $rawMessage = $message['message'] ?? null;
        if (!is_string($rawMessage) || $rawMessage === '') {
            throw new \DomainException(
                'The PHP_CodeSniffer JSON report contains a message without a usable identifier or text.',
            );
        }

        $cleanedMessage = trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $rawMessage), ' ');
        if ($cleanedMessage === '') {
            throw new \DomainException(
                'The PHP_CodeSniffer JSON report contains a message without a usable identifier or text.',
            );
        }

        $typeValue = $message['type'] ?? null;
        $type = (is_string($typeValue) && $typeValue !== '' && preg_match('/[\x00-\x1F\x7F]/', $typeValue) !== 1)
            ? $typeValue
            : null;

        $severityValue = $message['severity'] ?? null;
        $severity = is_int($severityValue) ? (string) $severityValue : null;

        $lineValue = $message['line'] ?? null;
        $line = (is_int($lineValue) && $lineValue >= 1) ? $lineValue : null;

        $columnValue = $message['column'] ?? null;
        $column = (is_int($columnValue) && $columnValue >= 1) ? $columnValue : null;

        [$mappingStatus, $mappedRuleIds] = $this->mappingFor($source);

        return new VerificationFinding(
            EvidenceClass::ExternalCompatibility,
            [$invocationId],
            $source,
            $type,
            $severity,
            $cleanedMessage,
            $file,
            $line,
            $column,
            null,
            $mappingStatus,
            $mappedRuleIds,
        );
    }

    /** @return array{MappingStatus, list<string>} exact array_key_exists lookup; never fuzzy or regex-based */
    private function mappingFor(string $sniffId): array
    {
        if (array_key_exists($sniffId, self::SNIFF_RULE_MAP)) {
            return [MappingStatus::Mapped, self::SNIFF_RULE_MAP[$sniffId]];
        }

        return [MappingStatus::Unmapped, []];
    }

    /**
     * @param  list<VerificationFinding> $findings
     * @return list<VerificationFinding>
     */
    private static function dedupe(array $findings): array
    {
        $seen = [];
        $deduped = [];
        foreach ($findings as $finding) {
            $key = serialize($finding->sortKey());
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $finding;
        }

        return $deduped;
    }

    private static function isNonNegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    /** @param list<VerificationInvocation> $executed */
    private function failed(array $executed, ?string $toolVersion, VerificationReason $reason): AdapterResult
    {
        return new AdapterResult(
            AdapterOutcome::Failed,
            ProjectionStatus::Supported,
            $toolVersion,
            $executed,
            [],
            $reason,
        );
    }

    /** @param list<VerificationInvocation> $executed */
    private function unavailable(array $executed, ?string $toolVersion, VerificationReason $reason): AdapterResult
    {
        return new AdapterResult(
            AdapterOutcome::Unavailable,
            ProjectionStatus::Supported,
            $toolVersion,
            $executed,
            [],
            $reason,
        );
    }

    /**
     * @param list<VerificationInvocation> $executed
     * @param list<VerificationFinding>    $findings
     */
    private function completed(array $executed, ?string $toolVersion, array $findings): AdapterResult
    {
        return new AdapterResult(
            AdapterOutcome::Completed,
            ProjectionStatus::Supported,
            $toolVersion,
            $executed,
            $findings,
            null,
        );
    }
}

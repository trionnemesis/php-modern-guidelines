<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

use Composer\Semver\Constraint\ConstraintInterface;
use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Exception\UnresolvablePolicyException;
use ModernPhpGuidelines\Php\KnownPhpMinors;
use ModernPhpGuidelines\Php\MinorRangeCalculator;

/**
 * The resolver: precedence, mode semantics, two-axis ceilings, coverage, confidence, warnings.
 */
final class PolicyResolver
{
    public function __construct(
        private readonly ComposerInputReader $reader,
        private readonly MinorRangeCalculator $calculator,
    ) {}

    /**
     * Reads the project from disk, then resolves. The one call slice D makes.
     *
     * @throws InputException              malformed caller input or malformed project files (exit 2)
     * @throws UnresolvablePolicyException the effective constraint allows no known minor (exit 4)
     */
    public function resolve(PolicyRequest $request): ResolvedPolicy
    {
        return $this->resolveFrom($request, $this->reader->read($request->projectRoot));
    }

    /**
     * Filesystem-free core: the precedence ladder, mode semantics and ceilings, coverage, confidence
     * and warnings.
     *
     * @throws InputException|UnresolvablePolicyException
     */
    public function resolveFrom(PolicyRequest $request, ProjectInputs $inputs): ResolvedPolicy
    {
        $warnings = [];

        foreach ($inputs->warningCodes as $code) {
            if ($code === WarningCatalogue::CODE_PLATFORM_OVERRIDE_DISABLED) {
                $warnings[] = WarningCatalogue::format(WarningCatalogue::CODE_PLATFORM_OVERRIDE_DISABLED);
            } elseif ($code === WarningCatalogue::CODE_COMPOSER_LOCK_PLATFORM_MISMATCH) {
                $warnings[] = WarningCatalogue::format(
                    WarningCatalogue::CODE_COMPOSER_LOCK_PLATFORM_MISMATCH,
                    (string) $inputs->lockPlatformOverride,
                    (string) $inputs->platformOverride,
                );
            }
        }

        // Parse the declared constraint once, up front. It is used both as the rank-6 effective
        // constraint and, regardless of which rank wins, for warnings 4/5/6's "declared constraint's
        // allowed known minors" comparisons.
        $declaredAllowedMinors = null;
        if ($inputs->declaredConstraint !== null) {
            try {
                $declaredConstraintParsed = $this->calculator->parse($inputs->declaredConstraint);
            } catch (InputException $e) {
                throw new InputException(sprintf(
                    'Could not parse the PHP constraint "%s" from composer.json: %s',
                    $inputs->declaredConstraint,
                    $e->getMessage(),
                ));
            }

            $declaredAllowedMinors = $this->calculator->allowedKnownMinors($declaredConstraintParsed);
        }

        // platform_override output field: single-source definition, independent of which rank wins.
        $platformOverrideField = $inputs->platformOverride ?? $inputs->lockPlatformOverride;

        // sources[]: fixed positions, independent of which rank wins.
        $sources = [];
        if ($request->phpOverride !== null) {
            $sources[] = new PolicySource(SourceType::CliPhpVersion, null, $request->phpOverride);
        }

        $observedFull = null;

        if ($request->mode === ResolutionMode::RuntimeObserved) {
            $observedFull = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
            $sources[] = new PolicySource(SourceType::Runtime, null, $observedFull);
        }

        if ($inputs->platformKeyPresent) {
            $sources[] = new PolicySource(SourceType::ComposerPlatformPhp, 'composer.json', $inputs->platformOverride);
        }

        if ($inputs->composerLockExists) {
            $sources[] = new PolicySource(SourceType::ComposerLock, 'composer.lock', $inputs->lockPlatformOverride);
        }

        $sources[] = new PolicySource(
            SourceType::ComposerRequirePhp,
            $inputs->composerJsonExists ? 'composer.json' : null,
            $inputs->declaredConstraint,
        );

        // Precedence ladder.
        if ($request->phpOverride !== null) {
            // Rank 1.
            try {
                $effectiveConstraint = $this->calculator->parse($request->phpOverride);
            } catch (InputException $e) {
                throw new InputException(sprintf(
                    'Could not parse the PHP constraint "%s" given via --php: %s',
                    $request->phpOverride,
                    $e->getMessage(),
                ));
            }

            [$allowedMinors, $featureCeiling, $lifecycleCeiling, $coverageStatus, $openUpperBound, $rankWarnings] =
                $this->resolveConstraintRank(1, $effectiveConstraint, $request->phpOverride, $request->mode, $inputs, $declaredAllowedMinors);
            $confidence = Confidence::Explicit;
            array_push($warnings, ...$rankWarnings);
        } elseif ($request->mode === ResolutionMode::RuntimeObserved) {
            // Rank 3.
            $observedMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $allowedMinors = [$observedMinor];
            $featureCeiling = $observedMinor;
            $lifecycleCeiling = $observedMinor;
            $openUpperBound = false;
            $confidence = Confidence::Observed;

            if (KnownPhpMinors::contains($observedMinor)) {
                $coverageStatus = CoverageStatus::Complete;
            } else {
                $coverageStatus = CoverageStatus::CoverageGap;
                $warnings[] = WarningCatalogue::format(
                    WarningCatalogue::CODE_RUNTIME_OUTSIDE_KNOWN_MINORS,
                    $observedMinor,
                    KnownPhpMinors::KNOWN_MIN,
                    KnownPhpMinors::KNOWN_MAX,
                );
            }

            if ($inputs->declaredConstraint !== null
                && !in_array($observedMinor, $declaredAllowedMinors, true)) {
                $warnings[] = WarningCatalogue::format(
                    WarningCatalogue::CODE_RUNTIME_OUTSIDE_DECLARED_CONSTRAINT,
                    (string) $observedFull,
                    $inputs->declaredConstraint,
                );
            }
        } elseif ($inputs->platformOverride !== null) {
            // Rank 4.
            $effectiveConstraint = $this->calculator->parse($inputs->platformOverride);

            [$allowedMinors, $featureCeiling, $lifecycleCeiling, $coverageStatus, $openUpperBound, $rankWarnings] =
                $this->resolveConstraintRank(4, $effectiveConstraint, $inputs->platformOverride, $request->mode, $inputs, $declaredAllowedMinors);
            $confidence = Confidence::Declared;
            array_push($warnings, ...$rankWarnings);
        } elseif ($inputs->lockPlatformOverride !== null) {
            // Rank 5.
            $effectiveConstraint = $this->calculator->parse($inputs->lockPlatformOverride);

            [$allowedMinors, $featureCeiling, $lifecycleCeiling, $coverageStatus, $openUpperBound, $rankWarnings] =
                $this->resolveConstraintRank(5, $effectiveConstraint, $inputs->lockPlatformOverride, $request->mode, $inputs, $declaredAllowedMinors);
            $confidence = Confidence::Declared;
            array_push($warnings, ...$rankWarnings);
        } elseif ($inputs->declaredConstraint !== null) {
            // Rank 6.
            $effectiveConstraint = $this->calculator->parse($inputs->declaredConstraint);

            [$allowedMinors, $featureCeiling, $lifecycleCeiling, $coverageStatus, $openUpperBound, $rankWarnings] =
                $this->resolveConstraintRank(6, $effectiveConstraint, $inputs->declaredConstraint, $request->mode, $inputs, $declaredAllowedMinors);
            $confidence = Confidence::Declared;
            array_push($warnings, ...$rankWarnings);
        } else {
            // Rank 7: nothing found. Conservative fallback, never fails closed.
            $confidence = Confidence::Unresolved;
            $coverageStatus = CoverageStatus::Unknown;
            $openUpperBound = false;

            $warnings[] = WarningCatalogue::format(
                WarningCatalogue::CODE_NO_PHP_CONSTRAINT_DECLARED,
                KnownPhpMinors::KNOWN_MIN,
                KnownPhpMinors::KNOWN_MAX,
            );

            $rankWarnings = [];
            [$allowedMinors, $featureCeiling, $lifecycleCeiling] = $this->applyModeNarrowing(
                $request->mode,
                KnownPhpMinors::all(),
                $rankWarnings,
            );
            array_push($warnings, ...$rankWarnings);
        }

        // Warning 5 applies uniformly to whichever value populates the platform_override field,
        // regardless of which rank actually won (§3.5).
        if ($platformOverrideField !== null && $inputs->declaredConstraint !== null) {
            $platformMinor = KnownPhpMinors::normalizeToMinor($platformOverrideField);
            if ($platformMinor !== null && !in_array($platformMinor, $declaredAllowedMinors, true)) {
                $warnings[] = WarningCatalogue::format(
                    WarningCatalogue::CODE_PLATFORM_OVERRIDE_OUTSIDE_DECLARED_CONSTRAINT,
                    $platformOverrideField,
                    $inputs->declaredConstraint,
                );
            }
        }

        $coverage = new Coverage($coverageStatus, KnownPhpMinors::KNOWN_MIN, KnownPhpMinors::KNOWN_MAX, $openUpperBound);

        return new ResolvedPolicy(
            mode: $request->mode,
            projectRoot: $inputs->projectRoot,
            declaredConstraint: $inputs->declaredConstraint,
            allowedMinors: $allowedMinors,
            featureCeiling: $featureCeiling,
            lifecycleCeiling: $lifecycleCeiling,
            platformOverride: $platformOverrideField,
            observedRuntime: $observedFull,
            coverage: $coverage,
            confidence: $confidence,
            sources: $sources,
            warnings: WarningCatalogue::sortByCatalogueOrder($warnings),
        );
    }

    /**
     * Everything that depends on having chosen a concrete effective constraint: the empty-intersection
     * fail-closed check, conflict.php subtraction (rank 6 only), coverage bounds, mode narrowing, and
     * the coverage/cli-override warnings.
     *
     * @param  list<string>|null $declaredAllowedMinors
     * @return array{0: list<string>, 1: string, 2: string, 3: CoverageStatus, 4: bool, 5: list<string>}
     *
     * @throws UnresolvablePolicyException
     */
    private function resolveConstraintRank(
        int $rank,
        ConstraintInterface $effectiveConstraint,
        string $constraintString,
        ResolutionMode $mode,
        ProjectInputs $inputs,
        ?array $declaredAllowedMinors,
    ): array {
        $warnings = [];

        $rawAllowed = $this->calculator->allowedKnownMinors($effectiveConstraint);
        if ($rawAllowed === []) {
            throw new UnresolvablePolicyException(sprintf(
                'The PHP constraint "%s" allows no PHP minor known to this tool (8.2-8.5). No policy can be resolved; this tool does not guess.',
                $constraintString,
            ));
        }

        $belowMin = $this->calculator->allowsBelowKnownMin($effectiveConstraint);
        $aboveMax = $this->calculator->allowsAboveKnownMax($effectiveConstraint);
        $coverageStatus = ($belowMin || $aboveMax) ? CoverageStatus::CoverageGap : CoverageStatus::Complete;

        $allowedAfterConflict = $rawAllowed;

        if ($rank === 6 && $inputs->conflictConstraint !== null) {
            $conflictParsed = $this->calculator->parse($inputs->conflictConstraint);
            $afterConflict = $this->calculator->subtractConflict($rawAllowed, $conflictParsed);

            if ($afterConflict === []) {
                throw new UnresolvablePolicyException(sprintf(
                    'The PHP constraint "%s" combined with conflict "%s" allows no PHP minor known to this tool (8.2-8.5). No policy can be resolved; this tool does not guess.',
                    $constraintString,
                    $inputs->conflictConstraint,
                ));
            }

            if (count($afterConflict) < count($rawAllowed)) {
                $removed = array_values(array_diff($rawAllowed, $afterConflict));
                $warnings[] = WarningCatalogue::format(
                    WarningCatalogue::CODE_CONFLICT_PHP_APPLIED,
                    $inputs->conflictConstraint,
                    implode(', ', $removed),
                );
            }

            $allowedAfterConflict = $afterConflict;
        }

        [$allowedMinors, $featureCeiling, $lifecycleCeiling] = $this->applyModeNarrowing($mode, $allowedAfterConflict, $warnings);

        if ($belowMin) {
            $warnings[] = WarningCatalogue::format(
                WarningCatalogue::CODE_BELOW_KNOWN_MIN,
                $constraintString,
                KnownPhpMinors::KNOWN_MIN,
                $featureCeiling,
            );
        }

        if ($aboveMax) {
            $warnings[] = $this->calculator->isUnbounded($effectiveConstraint)
                ? WarningCatalogue::format(WarningCatalogue::CODE_OPEN_UPPER_BOUND_UNBOUNDED, $constraintString, KnownPhpMinors::KNOWN_MAX)
                : WarningCatalogue::format(WarningCatalogue::CODE_OPEN_UPPER_BOUND_BOUNDED, $constraintString, KnownPhpMinors::KNOWN_MAX, KnownPhpMinors::KNOWN_MAX);
        }

        if ($rank === 1 && $inputs->declaredConstraint !== null && $declaredAllowedMinors !== null
            && array_diff($rawAllowed, $declaredAllowedMinors) !== []) {
            $warnings[] = WarningCatalogue::format(
                WarningCatalogue::CODE_CLI_OVERRIDE_OUTSIDE_DECLARED_CONSTRAINT,
                $constraintString,
                $inputs->declaredConstraint,
            );
        }

        return [$allowedMinors, $featureCeiling, $lifecycleCeiling, $coverageStatus, $aboveMax, $warnings];
    }

    /**
     * @param  list<string> $allowed ascending, non-empty
     * @param  list<string> $warnings appended to by reference when narrowing occurs
     * @return array{0: list<string>, 1: string, 2: string}
     */
    private function applyModeNarrowing(ResolutionMode $mode, array $allowed, array &$warnings): array
    {
        return match ($mode) {
            ResolutionMode::SingleTarget => $this->narrowToSingleTarget($allowed, $warnings),
            ResolutionMode::RangeSafe => [$allowed, $allowed[0], $allowed[count($allowed) - 1]],
            ResolutionMode::RuntimeObserved => throw new \LogicException(
                'applyModeNarrowing() must not be called for runtime-observed mode.',
            ),
        };
    }

    /**
     * @param  list<string> $allowed ascending, non-empty
     * @param  list<string> $warnings appended to by reference when narrowing occurs
     * @return array{0: list<string>, 1: string, 2: string}
     */
    private function narrowToSingleTarget(array $allowed, array &$warnings): array
    {
        $target = $allowed[0];

        if (count($allowed) > 1) {
            $warnings[] = WarningCatalogue::format(
                WarningCatalogue::CODE_SINGLE_TARGET_NARROWED,
                (string) count($allowed),
                implode(', ', $allowed),
                $target,
                $target,
            );
        }

        return [[$target], $target, $target];
    }
}

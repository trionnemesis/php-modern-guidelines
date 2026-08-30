<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Policy;

use ModernPhpGuidelines\Exception\InputException;
use ModernPhpGuidelines\Exception\UnresolvablePolicyException;
use ModernPhpGuidelines\Php\MinorRangeCalculator;
use ModernPhpGuidelines\Policy\ComposerInputReader;
use ModernPhpGuidelines\Policy\Confidence;
use ModernPhpGuidelines\Policy\CoverageStatus;
use ModernPhpGuidelines\Policy\PolicyRequest;
use ModernPhpGuidelines\Policy\PolicyResolver;
use ModernPhpGuidelines\Policy\ProjectInputs;
use ModernPhpGuidelines\Policy\ResolutionMode;
use PHPUnit\Framework\TestCase;

final class PolicyResolverTest extends TestCase
{
    private PolicyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PolicyResolver(new ComposerInputReader(), new MinorRangeCalculator());
    }

    // --- Case A: range-safe, declared caret constraint -------------------------------------------

    public function testCaseARangeSafeCaretConstraint(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '^8.2'),
        );

        self::assertSame(['8.2', '8.3', '8.4', '8.5'], $policy->allowedMinors);
        self::assertSame('8.2', $policy->featureCeiling);
        self::assertSame('8.5', $policy->lifecycleCeiling);
        self::assertSame(CoverageStatus::CoverageGap, $policy->coverage->status);
        self::assertTrue($policy->coverage->openUpperBound);
        self::assertSame(Confidence::Declared, $policy->confidence);
        self::assertSame(
            ['coverage.open_upper_bound_bounded: The constraint "^8.2" allows PHP minors newer than 8.5, which this tool does not know. Lifecycle guidance stops at 8.5.'],
            $policy->warnings,
        );
        self::assertSame('/project', $policy->projectRoot);
        self::assertNull($policy->platformOverride);
    }

    // --- Case J/K/T: empty intersection fails closed ----------------------------------------------

    public function testEmptyIntersectionFailsClosed(): void
    {
        $this->expectException(UnresolvablePolicyException::class);
        $this->expectExceptionMessage(
            'The PHP constraint "^7.4" allows no PHP minor known to this tool (8.2-8.5). No policy can be resolved; this tool does not guess.',
        );

        $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '^7.4'),
        );
    }

    public function testCliOverrideOutsideKnownMinorsFailsClosed(): void
    {
        $this->expectException(UnresolvablePolicyException::class);

        $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe, '^9.0'),
            $this->inputs(declaredConstraint: '^8.2'),
        );
    }

    // --- Cases L/M: no PHP constraint declared ------------------------------------------------------

    public function testNoRequirePhpFallsBackToAllKnownMinorsWithUnresolvedConfidence(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(composerJsonExists: true, declaredConstraint: null),
        );

        self::assertSame(['8.2', '8.3', '8.4', '8.5'], $policy->allowedMinors);
        self::assertSame(Confidence::Unresolved, $policy->confidence);
        self::assertSame(CoverageStatus::Unknown, $policy->coverage->status);
        self::assertFalse($policy->coverage->openUpperBound);
        self::assertCount(1, $policy->warnings);
        self::assertStringStartsWith('policy.no_php_constraint_declared: ', $policy->warnings[0]);
        self::assertSame('composer.json', $policy->sources[0]->path);
    }

    public function testNoComposerJsonAtAllFallsBackTheSameWayButSourcePathIsNull(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(composerJsonExists: false, declaredConstraint: null),
        );

        self::assertSame(['8.2', '8.3', '8.4', '8.5'], $policy->allowedMinors);
        self::assertSame(Confidence::Unresolved, $policy->confidence);
        self::assertNull($policy->sources[0]->path);
    }

    // --- Case N/O: platform override -----------------------------------------------------------

    public function testPlatformOverrideWinsAndSatisfiesDeclaredConstraint(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '^8.2', platformKeyPresent: true, platformOverride: '8.2.0'),
        );

        self::assertSame(['8.2'], $policy->allowedMinors);
        self::assertSame('8.2.0', $policy->platformOverride);
        self::assertSame([], $policy->warnings);
    }

    public function testPlatformOverrideOutsideDeclaredConstraintRaisesWarningButStillWins(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '^8.4', platformKeyPresent: true, platformOverride: '8.2.0'),
        );

        self::assertSame(['8.2'], $policy->allowedMinors);
        self::assertSame('8.2.0', $policy->platformOverride);
        self::assertSame(
            ['policy.platform_override_outside_declared_constraint: The platform override "8.2.0" is not satisfied by the declared constraint "^8.4"; the override still determines the policy.'],
            $policy->warnings,
        );
    }

    public function testPlatformDisabledIsIgnoredAndWarns(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(
                declaredConstraint: '^8.2',
                platformKeyPresent: true,
                platformOverride: null,
                warningCodes: ['input.platform_override_disabled'],
            ),
        );

        self::assertSame(['8.2', '8.3', '8.4', '8.5'], $policy->allowedMinors);
        self::assertNull($policy->platformOverride);
        self::assertSame(
            [
                'input.platform_override_disabled: composer.json sets config.platform.php to false; the platform override was ignored.',
                'coverage.open_upper_bound_bounded: The constraint "^8.2" allows PHP minors newer than 8.5, which this tool does not know. Lifecycle guidance stops at 8.5.',
            ],
            $policy->warnings,
        );
    }

    // --- Case P/Y: lock platform override --------------------------------------------------------

    public function testLockPlatformOverrideWinsWhenNoJsonPlatformOverride(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '^8.2', composerLockExists: true, lockPlatformKeyPresent: true, lockPlatformOverride: '8.3.0'),
        );

        self::assertSame(['8.3'], $policy->allowedMinors);
        self::assertSame('8.3.0', $policy->platformOverride);
        self::assertSame([], $policy->warnings);
    }

    public function testJsonPlatformOverrideBeatsLockPlatformOverride(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(
                declaredConstraint: '^8.2',
                platformKeyPresent: true,
                platformOverride: '8.2.0',
                composerLockExists: true,
                lockPlatformKeyPresent: true,
                lockPlatformOverride: '8.3.0',
                warningCodes: ['input.composer_lock_platform_mismatch'],
            ),
        );

        self::assertSame(['8.2'], $policy->allowedMinors);
        self::assertSame('8.2.0', $policy->platformOverride);
        self::assertSame(
            [
                'input.composer_lock_platform_mismatch: composer.lock platform-overrides.php is "8.3.0" but composer.json config.platform.php is "8.2.0"; composer.json wins. Run "composer update --lock" in the project to re-sync.',
            ],
            $policy->warnings,
        );
    }

    // --- Case X: conflict.php ---------------------------------------------------------------------

    public function testConflictPhpRemovesAMinorAndWarns(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '^8.2', conflictConstraint: '8.3.*'),
        );

        self::assertSame(['8.2', '8.4', '8.5'], $policy->allowedMinors);
        self::assertSame(
            [
                'coverage.open_upper_bound_bounded: The constraint "^8.2" allows PHP minors newer than 8.5, which this tool does not know. Lifecycle guidance stops at 8.5.',
                'policy.conflict_php_applied: composer.json conflict.php "8.3.*" removed PHP minor(s) 8.3 from the allowed range (D6). Conflict evidence cannot be recorded in sources[] because policy.schema.json\'s source.type enum has no value for it.',
            ],
            $policy->warnings,
        );
    }

    public function testConflictPhpThatEmptiesTheRangeFailsClosed(): void
    {
        $this->expectException(UnresolvablePolicyException::class);
        $this->expectExceptionMessage(
            'The PHP constraint "^8.2" combined with conflict ">=8.2" allows no PHP minor known to this tool (8.2-8.5). No policy can be resolved; this tool does not guess.',
        );

        $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '^8.2', conflictConstraint: '>=8.2'),
        );
    }

    public function testConflictPhpIsIgnoredWhenAnExplicitRankWins(): void
    {
        // D6: conflict.php only applies when require.php (rank 6) is the effective constraint.
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe, '8.3'),
            $this->inputs(declaredConstraint: '^8.2', conflictConstraint: '8.3.*'),
        );

        self::assertSame(['8.3'], $policy->allowedMinors);
        self::assertSame([], $policy->warnings);
    }

    // --- single-target mode -------------------------------------------------------------------

    public function testSingleTargetNarrowsToTheLowestMinorAndWarns(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::SingleTarget),
            $this->inputs(declaredConstraint: '^8.2'),
        );

        self::assertSame(['8.2'], $policy->allowedMinors);
        self::assertSame('8.2', $policy->featureCeiling);
        self::assertSame('8.2', $policy->lifecycleCeiling);
        // coverage still describes the pre-narrowing range (§3.6).
        self::assertSame(CoverageStatus::CoverageGap, $policy->coverage->status);
        self::assertTrue($policy->coverage->openUpperBound);
        self::assertCount(2, $policy->warnings);
        self::assertStringStartsWith('coverage.open_upper_bound_bounded: ', $policy->warnings[0]);
        self::assertStringStartsWith('mode.single_target_narrowed: single-target mode narrowed 4 allowed minors (8.2, 8.3, 8.4, 8.5) to the lowest, 8.2.', $policy->warnings[1]);
    }

    public function testSingleTargetOnAnAlreadySingleMinorDoesNotWarn(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::SingleTarget),
            $this->inputs(declaredConstraint: '~8.2.0'),
        );

        self::assertSame(['8.2'], $policy->allowedMinors);
        self::assertSame([], $policy->warnings);
    }

    // --- runtime-observed mode -----------------------------------------------------------------

    public function testRuntimeObservedUsesCurrentPhpVersion(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RuntimeObserved),
            $this->inputs(declaredConstraint: '^8.2'),
        );

        $expectedMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $expectedFull = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;

        self::assertSame([$expectedMinor], $policy->allowedMinors);
        self::assertSame($expectedMinor, $policy->featureCeiling);
        self::assertSame($expectedMinor, $policy->lifecycleCeiling);
        self::assertSame($expectedFull, $policy->observedRuntime);
        self::assertSame(Confidence::Observed, $policy->confidence);
        self::assertFalse($policy->coverage->openUpperBound);
        self::assertSame('runtime', $policy->sources[0]->type->value);
        self::assertSame($expectedFull, $policy->sources[0]->value);
    }

    public function testObservedRuntimeIsNullOutsideRuntimeObservedMode(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '^8.2'),
        );

        self::assertNull($policy->observedRuntime);
    }

    // --- --php + --mode=runtime-observed contradiction ------------------------------------------

    public function testPhpOverrideCombinedWithRuntimeObservedIsRejected(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('--php cannot be combined with --mode=runtime-observed.');

        new PolicyRequest('/project', ResolutionMode::RuntimeObserved, '8.4');
    }

    // --- --php override -------------------------------------------------------------------------

    public function testPhpOverrideWinsOverEverythingElse(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe, '8.4'),
            $this->inputs(declaredConstraint: '^8.2'),
        );

        self::assertSame(['8.4'], $policy->allowedMinors);
        self::assertSame(Confidence::Explicit, $policy->confidence);
        self::assertSame('cli.php_version', $policy->sources[0]->type->value);
        self::assertSame('8.4', $policy->sources[0]->value);
        self::assertNull($policy->sources[0]->path);
        self::assertSame([], $policy->warnings);
    }

    public function testCliOverrideOutsideDeclaredConstraintWarnsWhenBothAreKnown(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe, '8.5'),
            $this->inputs(declaredConstraint: '~8.2.0 || ~8.4.0'),
        );

        self::assertSame(['8.5'], $policy->allowedMinors);
        self::assertContains(
            'policy.cli_override_outside_declared_constraint: The --php value "8.5" allows PHP minors that the declared constraint "~8.2.0 || ~8.4.0" does not.',
            $policy->warnings,
        );
    }

    // --- malformed input ---------------------------------------------------------------------

    public function testUnparseableDeclaredConstraintThrows(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage(
            'Could not parse the PHP constraint "not a constraint" from composer.json: ',
        );

        $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: 'not a constraint'),
        );
    }

    public function testUnparseablePhpOverrideThrows(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage(
            'Could not parse the PHP constraint "not a constraint" given via --php: ',
        );

        $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe, 'not a constraint'),
            $this->inputs(declaredConstraint: '^8.2'),
        );
    }

    // --- coverage: below known min ---------------------------------------------------------------

    public function testBelowKnownMinRaisesWarningWithBothBoundsAndFeatureCeiling(): void
    {
        $policy = $this->resolver->resolveFrom(
            new PolicyRequest('/project', ResolutionMode::RangeSafe),
            $this->inputs(declaredConstraint: '>=8.0'),
        );

        self::assertSame(
            [
                'coverage.below_known_min: The constraint ">=8.0" allows PHP minors below 8.2, which this tool does not know. feature_ceiling 8.2 is a knowledge-limited ceiling and generated code may still exceed the project\'s real minimum.',
                'coverage.open_upper_bound_unbounded: The constraint ">=8.0" has no upper bound. Lifecycle guidance stops at 8.5; deprecations introduced in later PHP minors are not covered.',
            ],
            $policy->warnings,
        );
    }

    // --- helper ---------------------------------------------------------------------------------

    /** @param list<string> $warningCodes */
    private function inputs(
        ?string $declaredConstraint = null,
        ?string $conflictConstraint = null,
        bool $composerJsonExists = true,
        bool $platformKeyPresent = false,
        ?string $platformOverride = null,
        bool $lockPlatformKeyPresent = false,
        ?string $lockPlatformOverride = null,
        bool $composerLockExists = false,
        array $warningCodes = [],
    ): ProjectInputs {
        return new ProjectInputs(
            projectRoot: '/project',
            declaredConstraint: $declaredConstraint,
            conflictConstraint: $conflictConstraint,
            composerJsonExists: $composerJsonExists,
            platformKeyPresent: $platformKeyPresent,
            platformOverride: $platformOverride,
            lockPlatformKeyPresent: $lockPlatformKeyPresent,
            lockPlatformOverride: $lockPlatformOverride,
            composerLockExists: $composerLockExists,
            warningCodes: $warningCodes,
        );
    }
}

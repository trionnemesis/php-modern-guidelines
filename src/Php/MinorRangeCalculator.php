<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Php;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use ModernPhpGuidelines\Exception\InputException;

/**
 * composer/semver interval math: which known minors a `ConstraintInterface` allows, whether it leaks
 * below `known_min` or above `known_max`.
 *
 * `parse()` takes no context parameter, so its callers add the context. Every call site catches the
 * `InputException` this class throws and rethrows a new `InputException` whose message is the
 * work-order-pinned row for that input, with the original semver text appended.
 */
final class MinorRangeCalculator
{
    // NOTE: `new` in a default parameter value is PHP 8.1+, allowed.
    public function __construct(private readonly VersionParser $versionParser = new VersionParser()) {}

    /** @throws InputException when the constraint string cannot be parsed. */
    public function parse(string $constraint): ConstraintInterface
    {
        try {
            return $this->versionParser->parseConstraints($constraint);
        } catch (\UnexpectedValueException $e) {
            throw new InputException($e->getMessage(), 0, $e);
        }
    }

    /** @return list<string> known minors allowed by $constraint, ascending. May be empty. */
    public function allowedKnownMinors(ConstraintInterface $constraint): array
    {
        $allowed = [];
        foreach (KnownPhpMinors::all() as $minor) {
            if (Intervals::haveIntersections($constraint, $this->minorInterval($minor))) {
                $allowed[] = $minor;
            }
        }

        return $allowed;
    }

    /**
     * Remove from $minors every minor whose entire interval is covered by $conflict (D6).
     *
     * @param  list<string> $minors ascending
     * @return list<string> ascending, a subset of $minors. May be empty.
     */
    public function subtractConflict(array $minors, ConstraintInterface $conflict): array
    {
        $kept = [];
        foreach ($minors as $minor) {
            if (!Intervals::isSubsetOf($this->minorInterval($minor), $conflict)) {
                $kept[] = $minor;
            }
        }

        return $kept;
    }

    /** True when $constraint allows any version >= KnownPhpMinors::nextAfterKnownMax(). */
    public function allowsAboveKnownMax(ConstraintInterface $constraint): bool
    {
        $next = KnownPhpMinors::nextAfterKnownMax();
        [$major, $minor] = array_map('intval', explode('.', $next));

        return Intervals::haveIntersections(
            $constraint,
            $this->versionParser->parseConstraints(sprintf('>=%d.%d.0.0-dev', $major, $minor)),
        );
    }

    /** True when $constraint allows any version < KnownPhpMinors::KNOWN_MIN. */
    public function allowsBelowKnownMin(ConstraintInterface $constraint): bool
    {
        [$major, $minor] = array_map('intval', explode('.', KnownPhpMinors::KNOWN_MIN));

        return Intervals::haveIntersections(
            $constraint,
            $this->versionParser->parseConstraints(sprintf('<%d.%d.0.0-dev', $major, $minor)),
        );
    }

    /**
     * HEURISTIC, not a proof of unboundedness: true when the constraint still allows some version at or
     * above 99999.0.0 (e.g. '>=8.2', '*'). A constraint with an absurdly high but finite upper bound,
     * such as '>=8.2 <100000.0', is reported unbounded. Warning-text selection only — see the class
     * docblock and the work order for why this is safe. Do not reuse this method for policy math, and
     * do not rename it to something that sounds exact.
     */
    public function isUnbounded(ConstraintInterface $constraint): bool
    {
        return Intervals::haveIntersections(
            $constraint,
            $this->versionParser->parseConstraints('>=99999.0.0.0-dev'),
        );
    }

    private function minorInterval(string $minor): ConstraintInterface
    {
        [$major, $m] = array_map('intval', explode('.', $minor));

        return new MultiConstraint([
            $this->versionParser->parseConstraints(sprintf('>=%d.%d.0.0-dev', $major, $m)),
            $this->versionParser->parseConstraints(sprintf('<%d.%d.0.0-dev', $major, $m + 1)),
        ], true);
    }
}

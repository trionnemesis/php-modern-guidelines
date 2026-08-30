<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Policy;

/**
 * Fixed warning codes + message templates + fixed emission order.
 *
 * Every warning string is `"<code>: <sentence>"`. Emission order is exactly the catalogue order below,
 * independent of the order in which the conditions were discovered.
 */
final class WarningCatalogue
{
    public const CODE_PLATFORM_OVERRIDE_DISABLED = 'input.platform_override_disabled';
    public const CODE_COMPOSER_LOCK_PLATFORM_MISMATCH = 'input.composer_lock_platform_mismatch';
    public const CODE_NO_PHP_CONSTRAINT_DECLARED = 'policy.no_php_constraint_declared';
    public const CODE_CLI_OVERRIDE_OUTSIDE_DECLARED_CONSTRAINT = 'policy.cli_override_outside_declared_constraint';
    public const CODE_PLATFORM_OVERRIDE_OUTSIDE_DECLARED_CONSTRAINT = 'policy.platform_override_outside_declared_constraint';
    public const CODE_RUNTIME_OUTSIDE_DECLARED_CONSTRAINT = 'policy.runtime_outside_declared_constraint';
    public const CODE_BELOW_KNOWN_MIN = 'coverage.below_known_min';
    public const CODE_OPEN_UPPER_BOUND_UNBOUNDED = 'coverage.open_upper_bound_unbounded';
    public const CODE_OPEN_UPPER_BOUND_BOUNDED = 'coverage.open_upper_bound_bounded';
    public const CODE_RUNTIME_OUTSIDE_KNOWN_MINORS = 'coverage.runtime_outside_known_minors';
    public const CODE_SINGLE_TARGET_NARROWED = 'mode.single_target_narrowed';
    public const CODE_CONFLICT_PHP_APPLIED = 'policy.conflict_php_applied';

    /** Catalogue order, position 1..12. Binding — never reorder. */
    private const ORDER = [
        self::CODE_PLATFORM_OVERRIDE_DISABLED,
        self::CODE_COMPOSER_LOCK_PLATFORM_MISMATCH,
        self::CODE_NO_PHP_CONSTRAINT_DECLARED,
        self::CODE_CLI_OVERRIDE_OUTSIDE_DECLARED_CONSTRAINT,
        self::CODE_PLATFORM_OVERRIDE_OUTSIDE_DECLARED_CONSTRAINT,
        self::CODE_RUNTIME_OUTSIDE_DECLARED_CONSTRAINT,
        self::CODE_BELOW_KNOWN_MIN,
        self::CODE_OPEN_UPPER_BOUND_UNBOUNDED,
        self::CODE_OPEN_UPPER_BOUND_BOUNDED,
        self::CODE_RUNTIME_OUTSIDE_KNOWN_MINORS,
        self::CODE_SINGLE_TARGET_NARROWED,
        self::CODE_CONFLICT_PHP_APPLIED,
    ];

    /** @var array<string, string> */
    private const TEMPLATES = [
        self::CODE_PLATFORM_OVERRIDE_DISABLED =>
            'composer.json sets config.platform.php to false; the platform override was ignored.',
        self::CODE_COMPOSER_LOCK_PLATFORM_MISMATCH =>
            'composer.lock platform-overrides.php is "%s" but composer.json config.platform.php is "%s"; composer.json wins. Run "composer update --lock" in the project to re-sync.',
        self::CODE_NO_PHP_CONSTRAINT_DECLARED =>
            'No PHP constraint could be determined for this project (composer.json is missing, or declares no require.php); the policy falls back to every PHP minor known to this tool (%s-%s) with unresolved confidence.',
        self::CODE_CLI_OVERRIDE_OUTSIDE_DECLARED_CONSTRAINT =>
            'The --php value "%s" allows PHP minors that the declared constraint "%s" does not.',
        self::CODE_PLATFORM_OVERRIDE_OUTSIDE_DECLARED_CONSTRAINT =>
            'The platform override "%s" is not satisfied by the declared constraint "%s"; the override still determines the policy.',
        self::CODE_RUNTIME_OUTSIDE_DECLARED_CONSTRAINT =>
            'The observed runtime %s is not allowed by the declared constraint "%s".',
        self::CODE_BELOW_KNOWN_MIN =>
            'The constraint "%s" allows PHP minors below %s, which this tool does not know. feature_ceiling %s is a knowledge-limited ceiling and generated code may still exceed the project\'s real minimum.',
        self::CODE_OPEN_UPPER_BOUND_UNBOUNDED =>
            'The constraint "%s" has no upper bound. Lifecycle guidance stops at %s; deprecations introduced in later PHP minors are not covered.',
        self::CODE_OPEN_UPPER_BOUND_BOUNDED =>
            'The constraint "%s" allows PHP minors newer than %s, which this tool does not know. Lifecycle guidance stops at %s.',
        self::CODE_RUNTIME_OUTSIDE_KNOWN_MINORS =>
            'The observed runtime minor %s is outside this tool\'s known coverage (%s-%s); rule applicability is incomplete.',
        self::CODE_SINGLE_TARGET_NARROWED =>
            'single-target mode narrowed %s allowed minors (%s) to the lowest, %s. Both ceilings collapse to %s, so lifecycle guidance is narrowed too: deprecations and removals that only take effect on the dropped minors are reported as applicable rather than as deprecations. This is the mode\'s semantics, not a two-axis collapse. Use --php to choose a different target explicitly.',
        self::CODE_CONFLICT_PHP_APPLIED =>
            'composer.json conflict.php "%s" removed PHP minor(s) %s from the allowed range (D6). Conflict evidence cannot be recorded in sources[] because policy.schema.json\'s source.type enum has no value for it.',
    ];

    public static function format(string $code, string ...$args): string
    {
        $template = self::TEMPLATES[$code] ?? null;
        if ($template === null) {
            throw new \LogicException(sprintf('Unknown warning code "%s".', $code));
        }

        return $code . ': ' . vsprintf($template, $args);
    }

    /**
     * @param  list<string> $warnings
     * @return list<string>
     */
    public static function sortByCatalogueOrder(array $warnings): array
    {
        $rank = static function (string $warning): int {
            foreach (self::ORDER as $index => $code) {
                if (str_starts_with($warning, $code . ':')) {
                    return $index;
                }
            }

            return count(self::ORDER);
        };

        $indexed = $warnings;
        usort($indexed, static fn(string $a, string $b): int => $rank($a) <=> $rank($b));

        return $indexed;
    }
}

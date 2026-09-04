<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Rule;

use ModernPhpGuidelines\Rule\Rule;
use ModernPhpGuidelines\Rule\RuleKind;
use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Support\JsonPrinter;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every shipped seed rule under resources/rules/: schema-valid (already proven by loading it at all),
 * id == basename, id-prefix == category, source URL shape, checked_at, kind/lifecycle consistency,
 * package_constraints rendered as {} / \stdClass, byte-identity with its canonical re-encoding, and
 * the round-trip claim. Plus the exactly-24-files count and the two rule-16 (WORK-ORDER.md §6.3)
 * assertions.
 */
final class SeedRuleCatalogueTest extends TestCase
{
    private const EXPECTED_COUNT = 24;

    /** Pinned review dates carried by the catalogue's sources[0].checked_at (WORK-ORDER.md §6.3). */
    private const REVIEW_DATES = ['2026-08-30', '2026-09-04'];

    public function testExactlyTwentyFourRuleFilesAreShipped(): void
    {
        $registry = $this->loader()->loadDirectory(PackagePaths::rulesDirectory());

        self::assertSame(self::EXPECTED_COUNT, $registry->count());
    }

    #[DataProvider('ruleIds')]
    public function testEveryRuleValidatesAndRoundTrips(string $id): void
    {
        $registry = $this->loader()->loadDirectory(PackagePaths::rulesDirectory());
        $rule = $registry->get($id);

        self::assertSame($id, $rule->id, 'Rule id must equal its own file basename (enforced by the loader too).');
        self::assertSame(explode('.', $id)[0], $rule->category->value, 'Rule id prefix must equal its category.');

        $path = PackagePaths::rulesDirectory() . '/' . $id . '.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, sprintf('Could not read %s.', $path));

        // On-disk canonical form (WORK-ORDER.md §5.7): byte-identical to the JsonPrinter re-encoding
        // of the loaded value object, exactly one trailing newline.
        self::assertSame(
            JsonPrinter::encode($rule->toArray()) . "\n",
            $raw,
            sprintf('%s is not in JsonPrinter canonical form.', $id),
        );

        // package_constraints must be the literal two bytes {}, and must decode to \stdClass, never [].
        self::assertStringContainsString('"package_constraints": {}', $raw);
        $decoded = json_decode($raw);
        self::assertInstanceOf(\stdClass::class, $decoded);
        self::assertInstanceOf(\stdClass::class, $decoded->package_constraints);

        // Round-trip claim (WORK-ORDER.md §5.3 claim 1): loose equality only — toArray() mints a
        // fresh \stdClass for package_constraints on every call, so assertSame()/=== must not be used.
        self::assertEquals($rule->toArray(), Rule::fromArray($rule->toArray())->toArray());
        self::assertSame(
            JsonPrinter::encode($rule->toArray()),
            JsonPrinter::encode(Rule::fromArray($rule->toArray())->toArray()),
        );

        // Exactly one sources[] entry per rule (WORK-ORDER.md §6.3), in the pinned canonical URL form,
        // with the pinned checked_at date.
        self::assertCount(1, $rule->sources, sprintf('%s must ship exactly one sources[] entry.', $id));
        $source = $rule->sources[0];
        self::assertSame('php_source_upgrading', $source->type);
        self::assertMatchesRegularExpression(
            '#^https://raw\.githubusercontent\.com/php/php-src/php-8\.[2-5]\.0/UPGRADING$#',
            $source->url,
        );
        self::assertContains($source->checkedAt, self::REVIEW_DATES, sprintf('%s: unexpected checked_at review date.', $id));

        // Kind -> required non-null lifecycle field (WORK-ORDER.md §6.2).
        match ($rule->kind) {
            RuleKind::Feature => self::assertNotNull($rule->introducedIn, $id . ': feature rules must set introduced_in.'),
            RuleKind::Deprecated => self::assertNotNull($rule->deprecatedIn, $id . ': deprecated rules must set deprecated_in.'),
            RuleKind::Removed => self::assertNotNull($rule->removedIn, $id . ': removed rules must set removed_in.'),
            default => null,
        };

        if ($rule->category->value === 'extension') {
            self::assertNotNull($rule->extension, $id . ': category=extension rules must set extension.');
        }

        // §6.2's documented semantic overload: modern_preference / behavior_change rules must set
        // introduced_in (so they can be range-gated at all) and must name that value in details.
        if ($rule->kind === RuleKind::ModernPreference || $rule->kind === RuleKind::BehaviorChange) {
            $introducedIn = $rule->introducedIn;
            self::assertNotNull(
                $introducedIn,
                $id . ': ' . $rule->kind->value . ' rules must set introduced_in so they can be range-gated (§6.2).',
            );
            self::assertStringContainsString(
                $introducedIn,
                $rule->details,
                $id . ': details must explain what its own introduced_in means (§6.2).',
            );
        }
    }

    public function testFeatureCeilingGuardMakesNoLifecycleClaim(): void
    {
        $registry = $this->loader()->loadDirectory(PackagePaths::rulesDirectory());
        $rule = $registry->get('language.feature_ceiling_guard');

        self::assertStringContainsString('ADR-004', $rule->details);
        self::assertNull($rule->introducedIn);
        self::assertNull($rule->deprecatedIn);
        self::assertNull($rule->removedIn);
    }

    /** @return iterable<string, array{string}> */
    public static function ruleIds(): iterable
    {
        $ids = [
            'core.array_find_functions',
            'core.array_first_last',
            'core.dynamic_properties',
            'core.get_class_without_arguments',
            'core.json_validate',
            'core.override_attribute',
            'core.partially_supported_callables',
            'core.strtolower_locale_insensitive',
            'core.trigger_error_e_user_error',
            'core.utf8_encode_decode',
            'extension.curl_close',
            'extension.imap_unbundled',
            'extension.mysqli_driver_reconnect',
            'language.asymmetric_property_visibility',
            'language.backtick_shell_exec',
            'language.dollar_brace_string_interpolation',
            'language.feature_ceiling_guard',
            'language.implicitly_nullable_parameter_types',
            'language.new_without_parentheses',
            'language.non_canonical_cast_names',
            'language.pipe_operator',
            'language.property_hooks',
            'language.readonly_classes',
            'language.typed_class_constants',
        ];

        foreach ($ids as $id) {
            yield $id => [$id];
        }
    }

    private function loader(): RuleLoader
    {
        return new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath()));
    }
}

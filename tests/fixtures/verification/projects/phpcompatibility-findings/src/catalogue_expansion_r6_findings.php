<?php

/**
 * The one round 6 sniff newly mapped in PhpCompatibilityAdapter::SNIFF_RULE_MAP that this fixture tree
 * does not already trigger elsewhere: PHPCompatibility.Keywords.ForbiddenClassAlias.Found, the sole
 * proven mapping among the eight behavior_change rules added this round (WAVE2-R6-BRIEF.md), mapped to
 * core.class_alias_reserved_names. This sniff id is broader than what this rule documents: the same id
 * also fires for `int`, `string`, `bool` and `false` ("since PHP 7.0") and `never` ("since PHP 8.1"), all
 * already true at testVersion 8.2 alone, so this file deliberately uses only the two names
 * UPGRADING-8.5.0 actually names for PHP 8.5 - "array" and "callable" - never any other reserved word.
 * Probed directly with the CI-pinned analyzer before being written here: against this exact construct,
 * testVersion 8.2, 8.3, 8.4 and 8.2-8.4 each produce zero findings, while testVersion 8.5 and 8.2-8.5
 * each produce exactly two, both this sniff id.
 */
class ClassAliasReservedNameTarget
{
}

function classAliasReservedNameCalls(): void
{
    // PHPCompatibility.Keywords.ForbiddenClassAlias.Found ("array" - a type keyword forbidden as a
    // class_alias() name since PHP 8.5)
    class_alias(ClassAliasReservedNameTarget::class, 'array');

    // PHPCompatibility.Keywords.ForbiddenClassAlias.Found ("callable" - the other name PHP 8.5 newly
    // forbids; every other reserved type keyword was already forbidden on an earlier minor and is
    // deliberately not used here)
    class_alias(ClassAliasReservedNameTarget::class, 'callable');
}

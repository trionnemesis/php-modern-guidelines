<?php

/**
 * The one round 7 sniff newly mapped in PhpCompatibilityAdapter::SNIFF_RULE_MAP that this fixture tree
 * does not already trigger elsewhere: PHPCompatibility.IniDirectives.RemovedIniDirectives.disable_classesRemoved,
 * the sole proven mapping among the eight rules added this round (WAVE2-R7-BRIEF.md), mapped to
 * core.disable_classes_ini. The other 7 of round 7's eight new rules (core.attribute_target_validation,
 * core.file_flags_validation, core.http_build_query_backed_enums, core.loose_object_boolean_comparison,
 * core.printf_empty_precision, core.proc_get_status_repeated_calls, core.trait_static_property_redeclaration)
 * ship with an empty verification.phpcompatibility and need no fixture here: PHPCompatibility detects whether
 * a symbol or directive exists across a version range, and every one of those 7 rules instead documents
 * behaviour that changes while the same symbol keeps existing unchanged - structurally invisible to it.
 *
 * Both call sites below name the same removed directive and report the same sniff id, at two different
 * severities. Probed directly with the CI-pinned analyzer before being written here: testVersion 8.2, 8.3,
 * 8.4 and 8.2-8.4 each produce zero findings on this file; testVersion 8.5 and 8.2-8.5 each produce exactly
 * two, both this sniff id - one WARNING (the ini_get() read) and one ERROR (the ini_set() write) - agreeing
 * exactly with core.disable_classes_ini's own committed bisection.
 */
function disableClassesIniRead(): void
{
    // PHPCompatibility.IniDirectives.RemovedIniDirectives.disable_classesRemoved (read call site, reported
    // as WARNING - confirmed by isolating this call alone before it was combined with the write call below)
    ini_get('disable_classes');
}

function disableClassesIniWrite(): void
{
    // PHPCompatibility.IniDirectives.RemovedIniDirectives.disable_classesRemoved (write call site, reported
    // as ERROR - the sharper severity, since this attempts to change a directive about to stop existing)
    ini_set('disable_classes', '');
}

<?php

/**
 * One occurrence of each v0.3.5 (round 4, finishing Tier A) sniff newly mapped in
 * PhpCompatibilityAdapter::SNIFF_RULE_MAP that this fixture tree does not already trigger elsewhere: the
 * eight-rule expansion's 8 new sniff ids (WAVE2-R4-BRIEF.md Task 1/3). Probed directly with the
 * CI-pinned analyzer before being written here; every id below is measured, not assumed.
 */
class _
{
    // PHPCompatibility.Classes.ForbiddenClassNameUnderscore.Deprecated (one declaration is enough; the
    // same sniff also covers interface/trait/enum declarations named "_", not only class).
}

function dateRfc7231Constant(): void
{
    // PHPCompatibility.Constants.RemovedConstants.date_rfc7231Deprecated
    $format = DATE_RFC7231;
    echo $format;
}

function requestParseBodyCall(): void
{
    // PHPCompatibility.FunctionUse.NewFunctions.request_parse_bodyFound
    request_parse_body();
}

function getDefinedFunctionsExcludeDisabledCall(): void
{
    // PHPCompatibility.FunctionUse.RemovedFunctionParameters.get_defined_functions_exclude_disabledDeprecated
    $functions = get_defined_functions(true);
    echo count($functions);
}

function mysqliStoreResultModeCall(): void
{
    $link = mysqli_connect('localhost', 'user', 'pass', 'db');
    // PHPCompatibility.FunctionUse.RemovedFunctionParameters.mysqli_store_result_modeDeprecated (a
    // literal $mode value is used deliberately so this line does not also re-trigger the
    // mysqli_store_result_copy_dataDeprecated constant finding, which this catalogue deliberately
    // leaves unmapped).
    mysqli_store_result($link, 0);
}

function lcgValueCall(): void
{
    // PHPCompatibility.FunctionUse.RemovedFunctions.lcg_valueDeprecated
    $value = lcg_value();
    echo $value;
}

function registerArgcArgvIni(): void
{
    // PHPCompatibility.IniDirectives.RemovedIniDirectives.register_argc_argvDeprecated
    ini_set('register_argc_argv', '0');
}

function reportMemleaksIni(): void
{
    // PHPCompatibility.IniDirectives.RemovedIniDirectives.report_memleaksDeprecated
    ini_set('report_memleaks', '0');
}

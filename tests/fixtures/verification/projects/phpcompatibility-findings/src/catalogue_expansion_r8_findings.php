<?php

/**
 * All eight round 8 rules (WAVE2-R8-BRIEF.md) are drawn from UPGRADING's New Functions sections and every
 * one ships a proven verification.phpcompatibility mapping - the round's headline, since New Functions is
 * exactly the question PHPCompatibility was built to answer. This file calls all 15 mapped sniffs exactly
 * once, so each contributes exactly one finding (confirmed by grepping the existing fixture tree for all
 * 15 function names before this file was added: none was already called anywhere else in the tree).
 *
 * Floor-direction proof, probed directly against this exact file with the CI-pinned analyzer
 * (squizlabs/php_codesniffer 3.13.6 + phpcompatibility/php-compatibility 10.0.0-alpha2), ceiling fixed at
 * 8.5 throughout:
 *
 *   testVersion  findings  what dropped out
 *   8.2-8.5      15        - (all 15 fire)
 *   8.3-8.5      14        the one 8.3 function (mb_str_pad)
 *   8.4-8.5      3         the eleven 8.4 functions (fpow; mb_trim, mb_ltrim, mb_rtrim; mb_ucfirst,
 *                          mb_lcfirst; bcfloor, bcceil, bcround, bcdivmod; grapheme_str_split)
 *   8.5-8.5      0         the three 8.5 functions (get_error_handler, get_exception_handler,
 *                          curl_multi_get_handles)
 *
 * This is the opposite mechanism from a removed/deprecated sniff, where raising the *ceiling* makes a
 * finding appear (proven by rounds 7 and 8's own disable_classes/e_strict fixtures): a New* sniff fires
 * when the testVersion *floor* is below the function's introduced_in and falls silent once the floor
 * reaches it. The ceiling never moves across the table above; only the floor does, and the resulting
 * 15/14/3/0 ladder partitions the batch exactly by introduced_in (1 at 8.3, 11 at 8.4, 3 at 8.5) - itself
 * a check on the eight rules' version claims.
 */
function newErrorAndExceptionHandlerIntrospection(): void
{
    // PHPCompatibility.FunctionUse.NewFunctions.get_error_handlerFound (core.get_error_exception_handler, 8.5)
    $hadErrorHandler = get_error_handler() !== null;

    // PHPCompatibility.FunctionUse.NewFunctions.get_exception_handlerFound (core.get_error_exception_handler, 8.5)
    $hadExceptionHandler = get_exception_handler() !== null;
}

function ieee754Power(float $base, float $exponent): float
{
    // PHPCompatibility.FunctionUse.NewFunctions.fpowFound (core.fpow, 8.4)
    return fpow($base, $exponent);
}

function paddedDisplayName(string $name, int $width): string
{
    // PHPCompatibility.FunctionUse.NewFunctions.mb_str_padFound (extension.mb_str_pad, 8.3)
    return mb_str_pad($name, $width);
}

function normalizedFormField(string $value): string
{
    // PHPCompatibility.FunctionUse.NewFunctions.mb_trimFound (extension.mb_trim_functions, 8.4)
    $trimmed = mb_trim($value);

    // PHPCompatibility.FunctionUse.NewFunctions.mb_ltrimFound (extension.mb_trim_functions, 8.4)
    $leftTrimmed = mb_ltrim($trimmed);

    // PHPCompatibility.FunctionUse.NewFunctions.mb_rtrimFound (extension.mb_trim_functions, 8.4)
    return mb_rtrim($leftTrimmed);
}

function displayCaseFirst(string $name): string
{
    // PHPCompatibility.FunctionUse.NewFunctions.mb_ucfirstFound (extension.mb_case_first_functions, 8.4)
    $upper = mb_ucfirst($name);

    // PHPCompatibility.FunctionUse.NewFunctions.mb_lcfirstFound (extension.mb_case_first_functions, 8.4)
    return mb_lcfirst($upper);
}

/** @return array{0: string, 1: string, 2: string, 3: array{string, string}} */
function bcmathRounding(string $preciseTotal, string $shareCount): array
{
    // PHPCompatibility.FunctionUse.NewFunctions.bcfloorFound (extension.bcmath_rounding_functions, 8.4)
    $floor = bcfloor($preciseTotal);

    // PHPCompatibility.FunctionUse.NewFunctions.bcceilFound (extension.bcmath_rounding_functions, 8.4)
    $ceil = bcceil($preciseTotal);

    // PHPCompatibility.FunctionUse.NewFunctions.bcroundFound (extension.bcmath_rounding_functions, 8.4)
    $rounded = bcround($preciseTotal, 0);

    // PHPCompatibility.FunctionUse.NewFunctions.bcdivmodFound (extension.bcmath_rounding_functions, 8.4)
    $divmod = bcdivmod($preciseTotal, $shareCount, 0);

    return [$floor, $ceil, $rounded, $divmod];
}

function firstGrapheme(string $text): string
{
    // PHPCompatibility.FunctionUse.NewFunctions.grapheme_str_splitFound (extension.grapheme_str_split, 8.4)
    return grapheme_str_split($text)[0] ?? '';
}

/** @return list<\CurlHandle> */
function attachedMultiHandles(\CurlMultiHandle $multi): array
{
    // PHPCompatibility.FunctionUse.NewFunctions.curl_multi_get_handlesFound (extension.curl_multi_get_handles, 8.5)
    return curl_multi_get_handles($multi);
}

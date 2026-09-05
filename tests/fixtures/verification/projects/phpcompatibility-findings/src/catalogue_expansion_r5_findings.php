<?php

/**
 * One occurrence of each round 5 sniff newly mapped in PhpCompatibilityAdapter::SNIFF_RULE_MAP that this
 * fixture tree does not already trigger elsewhere: the 4 new sniff ids across the 3 of round 5's
 * eight new rules that carry a proven PHPCompatibility mapping (core.stream_context_set_option_arity,
 * core.csv_escape_parameter and core.socket_set_timeout — WAVE2-R5-BRIEF.md). Probed directly with the
 * CI-pinned analyzer before being written here; every id below is measured, not assumed.
 */
function streamContextSetOptionArityCall(): void
{
    $context = stream_context_create();
    // PHPCompatibility.FunctionUse.OptionalToRequiredFunctionParameters.stream_context_set_option_option_nameSoftRequired
    // PHPCompatibility.FunctionUse.OptionalToRequiredFunctionParameters.stream_context_set_option_valueSoftRequired
    // (the two-argument form, passing $wrapper_or_options as an array standing in for the whole options
    // structure. The four-argument per-option form - stream_context_set_option($context, 'http',
    // 'method', 'POST') - raises neither of these, and a genuine three-argument call throws a
    // ValueError at runtime instead of a deprecation notice, so neither is used here.)
    stream_context_set_option($context, ['http' => ['method' => 'POST']]);
}

function csvEscapeParameterCall(): void
{
    $stream = fopen('php://memory', 'r+');
    // PHPCompatibility.ParameterValues.RemovedProprietaryCSVEscaping.DeprecatedParamNotPassed (fputcsv()
    // called with no $escape argument; fgetcsv() and str_getcsv() report the same sniff id and are not
    // needed again here).
    fputcsv($stream, ['a', 'b']);
}

function socketSetTimeoutCall(): void
{
    $stream = fopen('php://memory', 'r+');
    // PHPCompatibility.FunctionUse.RemovedFunctions.socket_set_timeoutDeprecated
    socket_set_timeout($stream, 1);
}

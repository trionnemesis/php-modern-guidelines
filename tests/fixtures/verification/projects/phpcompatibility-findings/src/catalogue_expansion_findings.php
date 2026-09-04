<?php

/**
 * One occurrence of each v0.3.2 sniff newly mapped in PhpCompatibilityAdapter::SNIFF_RULE_MAP that this
 * fixture tree does not already trigger elsewhere. The remaining two new mappings
 * (FunctionUse.RemovedFunctions.utf8_encodeDeprecated / utf8_decodeDeprecated) are already proved by
 * unmapped_findings.php, which keeps calling utf8_encode()/utf8_decode() — those two findings move from
 * unmapped to mapped without this file's help.
 */
class GetClassCaller
{
    public function identify(): string
    {
        // PHPCompatibility.ParameterValues.RemovedGetClassNoArgs.ArgMissing
        return get_class();
    }
}

function triggerUserError(): void
{
    // PHPCompatibility.ParameterValues.RemovedTriggerErrorLevel.Deprecated
    trigger_error('fatal condition', E_USER_ERROR);
}

function nonCanonicalCasts(string $value): void
{
    // PHPCompatibility.TypeCasts.RemovedTypeCasts.booleanDeprecated
    $asBoolean = (boolean) $value;
    // PHPCompatibility.TypeCasts.RemovedTypeCasts.integerDeprecated
    $asInteger = (integer) $value;
    // PHPCompatibility.TypeCasts.RemovedTypeCasts.doubleDeprecated
    $asDouble = (double) $value;
    // PHPCompatibility.TypeCasts.RemovedTypeCasts.binaryDeprecated
    $asBinary = (binary) $value;
    echo $asBoolean, $asInteger, $asDouble, $asBinary;
}

function backtickShellExec(): void
{
    // PHPCompatibility.LanguageConstructs.RemovedLanguageConstructs.t_backtickDeprecated (reported once
    // per backtick token, so this single line reports the sniff twice).
    $listing = `ls`;
    echo $listing;
}

class AsymmetricVisibilityHolder
{
    // PHPCompatibility.Keywords.NewKeywords.t_private_setFound
    public private(set) string $label = '';

    // PHPCompatibility.Keywords.NewKeywords.t_protected_setFound
    public protected(set) string $name = '';
}

function newWithoutParenthesesDemo(): string
{
    // PHPCompatibility.Syntax.NewClassMemberAccessWithoutParentheses.Found
    return new DateTime('now')->format('Y-m-d');
}

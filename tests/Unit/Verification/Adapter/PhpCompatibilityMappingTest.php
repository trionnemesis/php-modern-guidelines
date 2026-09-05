<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Unit\Verification\Adapter;

use ModernPhpGuidelines\Rule\RuleLoader;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use ModernPhpGuidelines\Verification\Adapter\PhpCompatibilityAdapter;
use PHPUnit\Framework\TestCase;

/** The committed exact mapping, tested in both sniff -> rule and rule -> sniff directions. */
final class PhpCompatibilityMappingTest extends TestCase
{
    public function testCommittedMapMatchesTheReviewedTable(): void
    {
        self::assertSame([
            'PHPCompatibility.Classes.ForbiddenClassNameUnderscore.Deprecated' => ['core.underscore_class_name'],
            'PHPCompatibility.Classes.NewReadonlyClasses.AnonClass' => ['language.readonly_anonymous_classes'],
            'PHPCompatibility.Classes.NewStaticAvizProperties.Found' => ['language.static_asymmetric_visibility'],
            'PHPCompatibility.Classes.NewTypedConstants.Found' => ['language.typed_class_constants'],
            'PHPCompatibility.Classes.RemovedClasses.imap_connectionRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.assert_activeDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.assert_bailDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.assert_callbackDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.assert_exceptionDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.assert_warningDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.cl_expungeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.cp_moveRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.cp_uidRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.date_rfc7231Deprecated' => ['core.date_rfc7231'],
            'PHPCompatibility.Constants.RemovedConstants.e_strictDeprecated' => ['core.e_strict_constant'],
            'PHPCompatibility.Constants.RemovedConstants.enc7bitRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.enc8bitRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.encbase64Removed' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.encbinaryRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.encotherRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.encquotedprintableRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.ft_internalRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.ft_notRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.ft_peekRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.ft_prefetchtextRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.ft_uidRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.imap_closetimeoutRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.imap_gc_eltRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.imap_gc_envRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.imap_gc_textsRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.imap_opentimeoutRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.imap_readtimeoutRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.imap_writetimeoutRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.latt_haschildrenRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.latt_hasnochildrenRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.latt_markedRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.latt_noinferiorsRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.latt_noselectRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.latt_referralRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.latt_unmarkedRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_backup_logDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_grantDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_hostsDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_logDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_masterDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_replicaDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_slaveDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_statusDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_tablesDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_threadsDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Constants.RemovedConstants.nilDeprecatedRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_anonymousRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_debugRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_expungeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_halfopenRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_prototypeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_readonlyRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_secureRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_shortcacheRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.op_silentRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sa_allRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sa_messagesRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sa_recentRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sa_uidnextRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sa_uidvalidityRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sa_unseenRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.se_freeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.se_noprefetchRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.se_uidRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.so_freeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.so_noserverRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sortarrivalRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sortccRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sortdateRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sortfromRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sortsizeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sortsubjectRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.sorttoRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.st_setRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.st_silentRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.st_uidRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typeapplicationRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typeaudioRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typeimageRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typemessageRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typemodelRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typemultipartRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typeotherRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typetextRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.Constants.RemovedConstants.typevideoRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionDeclarations.RemovedImplicitlyNullableParam.Deprecated' => [
                'language.implicitly_nullable_parameter_types',
            ],
            'PHPCompatibility.FunctionUse.NewFunctions.array_allFound' => ['core.array_find_functions'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_anyFound' => ['core.array_find_functions'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_findFound' => ['core.array_find_functions'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_find_keyFound' => ['core.array_find_functions'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_firstFound' => ['core.array_first_last'],
            'PHPCompatibility.FunctionUse.NewFunctions.array_lastFound' => ['core.array_first_last'],
            'PHPCompatibility.FunctionUse.NewFunctions.json_validateFound' => ['core.json_validate'],
            'PHPCompatibility.FunctionUse.NewFunctions.request_parse_bodyFound' => ['core.request_parse_body'],
            'PHPCompatibility.FunctionUse.OptionalToRequiredFunctionParameters.stream_context_set_option_option_nameSoftRequired' => ['core.stream_context_set_option_arity'],
            'PHPCompatibility.FunctionUse.OptionalToRequiredFunctionParameters.stream_context_set_option_valueSoftRequired' => ['core.stream_context_set_option_arity'],
            'PHPCompatibility.FunctionUse.RemovedFunctionParameters.get_defined_functions_exclude_disabledDeprecated' => ['core.get_defined_functions_exclude_disabled'],
            'PHPCompatibility.FunctionUse.RemovedFunctionParameters.mysqli_store_result_modeDeprecated' => ['extension.mysqli_store_result_mode'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.assert_optionsDeprecated' => ['core.assert_options'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated' => ['extension.curl_close'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.curl_share_closeDeprecated' => ['extension.curl_share_close'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.finfo_closeDeprecated' => ['extension.finfo_close'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_8bitRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_alertsRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_appendRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_base64Removed' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_binaryRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_bodyRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_bodystructRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_checkRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_clearflag_fullRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_closeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_createRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_createmailboxRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_deleteRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_deletemailboxRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_errorsRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_expungeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_fetch_overviewRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_fetchbodyRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_fetchheaderRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_fetchmimeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_fetchstructureRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_fetchtextRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_gcRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_get_quotaRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_get_quotarootRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_getaclRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_getmailboxesRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_getsubscribedRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_headerinfoRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_headersRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_is_openRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_last_errorRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_listRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_listmailboxRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_listscanRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_listsubscribedRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_lsubRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_mailRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_mail_composeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_mail_copyRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_mail_moveRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_mailboxmsginfoRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_mime_header_decodeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_msgnoRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_mutf7_to_utf8Removed' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_num_msgRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_num_recentRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_openRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_pingRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_qprintRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_renameRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_renamemailboxRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_reopenRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_rfc822_parse_adrlistRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_rfc822_parse_headersRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_rfc822_write_addressRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_savebodyRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_scanRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_scanmailboxRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_searchRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_set_quotaRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_setaclRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_setflag_fullRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_sortRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_statusRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_subscribeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_threadRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_timeoutRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_uidRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_undeleteRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_unsubscribeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_utf7_decodeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_utf7_encodeRemoved' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_utf8Removed' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.imap_utf8_to_mutf7Removed' => ['extension.imap_unbundled'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.lcg_valueDeprecated' => ['core.lcg_value'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_killDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_pingDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_refreshDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.socket_set_timeoutDeprecated' => ['core.socket_set_timeout'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_decodeDeprecated' => ['core.utf8_encode_decode'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_encodeDeprecated' => ['core.utf8_encode_decode'],
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.imap_enable_insecure_rshRemoved' => [
                'extension.imap_unbundled',
            ],
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.mysqli_reconnectRemoved' => [
                'extension.mysqli_driver_reconnect',
            ],
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.register_argc_argvDeprecated' => ['core.register_argc_argv_ini'],
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.report_memleaksDeprecated' => ['core.report_memleaks_ini'],
            'PHPCompatibility.Keywords.ForbiddenClassAlias.Found' => ['core.class_alias_reserved_names'],
            'PHPCompatibility.Keywords.NewKeywords.t_private_setFound' => ['language.asymmetric_property_visibility'],
            'PHPCompatibility.Keywords.NewKeywords.t_protected_setFound' => ['language.asymmetric_property_visibility'],
            'PHPCompatibility.LanguageConstructs.RemovedLanguageConstructs.t_backtickDeprecated' => [
                'language.backtick_shell_exec',
            ],
            'PHPCompatibility.ParameterValues.RemovedGetClassNoArgs.ArgMissing' => [
                'core.get_class_without_arguments',
            ],
            'PHPCompatibility.ParameterValues.RemovedProprietaryCSVEscaping.DeprecatedParamNotPassed' => ['core.csv_escape_parameter'],
            'PHPCompatibility.ParameterValues.RemovedTriggerErrorLevel.Deprecated' => [
                'core.trigger_error_e_user_error',
            ],
            'PHPCompatibility.Syntax.NewClassMemberAccessWithoutParentheses.Found' => [
                'language.new_without_parentheses',
            ],
            'PHPCompatibility.Syntax.NewDynamicClassConstantFetch.Found' => ['language.dynamic_class_constant_fetch'],
            'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax' => [
                'language.dollar_brace_string_interpolation',
            ],
            'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax' => [
                'language.dollar_brace_string_interpolation',
            ],
            'PHPCompatibility.TypeCasts.RemovedTypeCasts.binaryDeprecated' => ['language.non_canonical_cast_names'],
            'PHPCompatibility.TypeCasts.RemovedTypeCasts.booleanDeprecated' => ['language.non_canonical_cast_names'],
            'PHPCompatibility.TypeCasts.RemovedTypeCasts.doubleDeprecated' => ['language.non_canonical_cast_names'],
            'PHPCompatibility.TypeCasts.RemovedTypeCasts.integerDeprecated' => ['language.non_canonical_cast_names'],
        ], self::committedMap());
    }

    public function testEveryMappedRuleIdExistsInTheCommittedCatalogue(): void
    {
        $rules = (new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath())))
            ->loadDirectory(PackagePaths::rulesDirectory());

        foreach (self::committedMap() as $sniffId => $ruleIds) {
            foreach ($ruleIds as $ruleId) {
                self::assertTrue(
                    $rules->has($ruleId),
                    sprintf('Mapped rule id "%s" (from sniff "%s") does not exist in the rule catalogue.', $ruleId, $sniffId),
                );
            }
        }
    }

    public function testMapKeysAreWellFormedSniffIdsUnderTheOwnedStandard(): void
    {
        $map = self::committedMap();
        $keys = array_keys($map);

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression(
                '/^PHPCompatibility\.[A-Za-z0-9]+\.[A-Za-z0-9]+\.[A-Za-z0-9_]+$/',
                $key,
            );
        }

        self::assertSame(array_values(array_unique($keys)), $keys);

        $sortedKeys = $keys;
        sort($sortedKeys, SORT_STRING);
        self::assertSame($sortedKeys, $keys, 'The committed map must be sorted by key.');
    }

    public function testMapValuesAreSortedUniqueAndNonEmpty(): void
    {
        foreach (self::committedMap() as $sniffId => $ruleIds) {
            self::assertNotSame([], $ruleIds, sprintf('Sniff "%s" maps to no rule id.', $sniffId));
            self::assertSame(
                array_values(array_unique($ruleIds)),
                $ruleIds,
                sprintf('Sniff "%s" rule ids must be unique.', $sniffId),
            );

            $sorted = $ruleIds;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $ruleIds, sprintf('Sniff "%s" rule ids must be sorted.', $sniffId));
        }
    }

    public function testNoInternalPseudoSniffIsMapped(): void
    {
        foreach (array_keys(self::committedMap()) as $key) {
            self::assertStringStartsNotWith('Internal.', $key);
        }
    }

    public function testRuleVerificationListsAreTheExactInverseOfTheCommittedMap(): void
    {
        $rules = (new RuleLoader(new JsonSchemaValidator(PackagePaths::ruleSchemaPath())))
            ->loadDirectory(PackagePaths::rulesDirectory());

        /** @var array<string, list<string>> $fromRuleFiles */
        $fromRuleFiles = [];
        foreach ($rules->all() as $rule) {
            $sniffIds = $rule->verification->phpcompatibility;
            $sorted = $sniffIds;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $sniffIds, sprintf('%s mappings must be sorted.', $rule->id));
            self::assertSame(
                array_values(array_unique($sniffIds)),
                $sniffIds,
                sprintf('%s mappings must be unique.', $rule->id),
            );

            foreach ($sniffIds as $sniffId) {
                $fromRuleFiles[$sniffId][] = $rule->id;
            }
        }

        ksort($fromRuleFiles, SORT_STRING);
        foreach ($fromRuleFiles as &$ruleIds) {
            sort($ruleIds, SORT_STRING);
        }
        unset($ruleIds);

        self::assertSame(self::committedMap(), $fromRuleFiles);
    }

    /** @return array<string, list<string>> */
    private static function committedMap(): array
    {
        /** @var array<string, list<string>> $value */
        $value = (new \ReflectionClassConstant(PhpCompatibilityAdapter::class, 'SNIFF_RULE_MAP'))->getValue();

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace ModernPhpGuidelines\Tests\Integration;

use ModernPhpGuidelines\ApplicationFactory;
use ModernPhpGuidelines\Command\ExitCode;
use ModernPhpGuidelines\Support\JsonSchemaValidator;
use ModernPhpGuidelines\Support\PackagePaths;
use ModernPhpGuidelines\Tests\Support\FixtureTreeSnapshot;
use ModernPhpGuidelines\Verification\Process\NativeProcessRunner;
use ModernPhpGuidelines\Verification\VerificationReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Drives the real, pinned PHP_CodeSniffer + PHPCompatibility installation named by
 * `MODERN_PHP_GUIDELINES_PHPCS` against the committed fixture trees. This class is deliberately not in
 * the `process-isolation` group: the two groups stay disjoint so each CI command has exactly one
 * precondition, even though every case here also executes a real child process.
 */
#[Group('real-analyzer')]
final class VerifyPhpCompatibilityAnalyzerTest extends TestCase
{
    private const FINDINGS_PROJECT_PATH = __DIR__ . '/../fixtures/verification/projects/phpcompatibility-findings';
    private const CLEAN_PROJECT_PATH = __DIR__ . '/../fixtures/verification/projects/phpcompatibility-clean';
    private const OR_CONSTRAINT_PROJECT_PATH = __DIR__ . '/../fixtures/projects/or-constraint';

    /**
     * The complete set of imap_* function names `src/imap_findings.php` calls that the
     * FunctionUse.RemovedFunctions sniff reports as "removed since PHP 8.4" because of the ext/imap
     * unbundling (cross-checked against phpcompatibility/php-compatibility 10.0.0-alpha2's own
     * removedFunctions table). imap_header is deliberately excluded: it reports a distinct, pre-floor
     * "removed since PHP 8.0" fact and must stay unmapped.
     *
     * This is one of four PHPCompatibility sniff families that report the same "ext/imap removed in
     * PHP 8.4" fact. See IMAP_UNBUNDLED_NON_FUNCTION_SNIFF_IDS below for the other three families
     * (Constants.RemovedConstants, Classes.RemovedClasses, IniDirectives.RemovedIniDirectives) — the map
     * is complete per-fact only with all four covered.
     *
     * @var list<string>
     */
    private const IMAP_UNBUNDLED_FUNCTION_NAMES = [
        'imap_8bit',
        'imap_alerts',
        'imap_append',
        'imap_base64',
        'imap_binary',
        'imap_body',
        'imap_bodystruct',
        'imap_check',
        'imap_clearflag_full',
        'imap_close',
        'imap_create',
        'imap_createmailbox',
        'imap_delete',
        'imap_deletemailbox',
        'imap_errors',
        'imap_expunge',
        'imap_fetch_overview',
        'imap_fetchbody',
        'imap_fetchheader',
        'imap_fetchmime',
        'imap_fetchstructure',
        'imap_fetchtext',
        'imap_gc',
        'imap_get_quota',
        'imap_get_quotaroot',
        'imap_getacl',
        'imap_getmailboxes',
        'imap_getsubscribed',
        'imap_headerinfo',
        'imap_headers',
        'imap_is_open',
        'imap_last_error',
        'imap_list',
        'imap_listmailbox',
        'imap_listscan',
        'imap_listsubscribed',
        'imap_lsub',
        'imap_mail',
        'imap_mail_compose',
        'imap_mail_copy',
        'imap_mail_move',
        'imap_mailboxmsginfo',
        'imap_mime_header_decode',
        'imap_msgno',
        'imap_mutf7_to_utf8',
        'imap_num_msg',
        'imap_num_recent',
        'imap_open',
        'imap_ping',
        'imap_qprint',
        'imap_rename',
        'imap_renamemailbox',
        'imap_reopen',
        'imap_rfc822_parse_adrlist',
        'imap_rfc822_parse_headers',
        'imap_rfc822_write_address',
        'imap_savebody',
        'imap_scan',
        'imap_scanmailbox',
        'imap_search',
        'imap_set_quota',
        'imap_setacl',
        'imap_setflag_full',
        'imap_sort',
        'imap_status',
        'imap_subscribe',
        'imap_thread',
        'imap_timeout',
        'imap_uid',
        'imap_undelete',
        'imap_unsubscribe',
        'imap_utf7_decode',
        'imap_utf7_encode',
        'imap_utf8',
        'imap_utf8_to_mutf7',
    ];

    /**
     * The remaining 70 sniff ids that report the same "ext/imap removed in PHP 8.4" fact through three
     * other PHPCompatibility sniff families, completing the map alongside IMAP_UNBUNDLED_FUNCTION_NAMES
     * above (75 + 68 + 1 + 1 = 145 ids total). Cross-checked against phpcompatibility/php-compatibility
     * 10.0.0-alpha2's own data tables: every entry across the RemovedFunctions, RemovedConstants,
     * RemovedClasses and RemovedIniDirectives tables carrying `'extension' => 'imap'` with
     * `'8.4' => true`, minus the 75 already listed above.
     *
     * 68 are `Constants.RemovedConstants.<name>Removed`, fired by `src/imap_all_surfaces.php`. Note
     * NIL's id is `nilDeprecatedRemoved`, not `nilRemoved`: NIL is separately deprecated since PHP 8.1,
     * on top of being removed in 8.4. One is `Classes.RemovedClasses.imap_connectionRemoved` (also
     * `src/imap_all_surfaces.php`, fired twice — once for the parameter type, once for the return type).
     * One is `IniDirectives.RemovedIniDirectives.imap_enable_insecure_rshRemoved`, fired twice by
     * `src/imap_ini.php` (once for `ini_set()`, once for `ini_get()`).
     *
     * @var list<string>
     */
    private const IMAP_UNBUNDLED_NON_FUNCTION_SNIFF_IDS = [
        'PHPCompatibility.Classes.RemovedClasses.imap_connectionRemoved',
        'PHPCompatibility.Constants.RemovedConstants.cl_expungeRemoved',
        'PHPCompatibility.Constants.RemovedConstants.cp_moveRemoved',
        'PHPCompatibility.Constants.RemovedConstants.cp_uidRemoved',
        'PHPCompatibility.Constants.RemovedConstants.enc7bitRemoved',
        'PHPCompatibility.Constants.RemovedConstants.enc8bitRemoved',
        'PHPCompatibility.Constants.RemovedConstants.encbase64Removed',
        'PHPCompatibility.Constants.RemovedConstants.encbinaryRemoved',
        'PHPCompatibility.Constants.RemovedConstants.encotherRemoved',
        'PHPCompatibility.Constants.RemovedConstants.encquotedprintableRemoved',
        'PHPCompatibility.Constants.RemovedConstants.ft_internalRemoved',
        'PHPCompatibility.Constants.RemovedConstants.ft_notRemoved',
        'PHPCompatibility.Constants.RemovedConstants.ft_peekRemoved',
        'PHPCompatibility.Constants.RemovedConstants.ft_prefetchtextRemoved',
        'PHPCompatibility.Constants.RemovedConstants.ft_uidRemoved',
        'PHPCompatibility.Constants.RemovedConstants.imap_closetimeoutRemoved',
        'PHPCompatibility.Constants.RemovedConstants.imap_gc_eltRemoved',
        'PHPCompatibility.Constants.RemovedConstants.imap_gc_envRemoved',
        'PHPCompatibility.Constants.RemovedConstants.imap_gc_textsRemoved',
        'PHPCompatibility.Constants.RemovedConstants.imap_opentimeoutRemoved',
        'PHPCompatibility.Constants.RemovedConstants.imap_readtimeoutRemoved',
        'PHPCompatibility.Constants.RemovedConstants.imap_writetimeoutRemoved',
        'PHPCompatibility.Constants.RemovedConstants.latt_haschildrenRemoved',
        'PHPCompatibility.Constants.RemovedConstants.latt_hasnochildrenRemoved',
        'PHPCompatibility.Constants.RemovedConstants.latt_markedRemoved',
        'PHPCompatibility.Constants.RemovedConstants.latt_noinferiorsRemoved',
        'PHPCompatibility.Constants.RemovedConstants.latt_noselectRemoved',
        'PHPCompatibility.Constants.RemovedConstants.latt_referralRemoved',
        'PHPCompatibility.Constants.RemovedConstants.latt_unmarkedRemoved',
        'PHPCompatibility.Constants.RemovedConstants.nilDeprecatedRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_anonymousRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_debugRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_expungeRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_halfopenRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_prototypeRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_readonlyRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_secureRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_shortcacheRemoved',
        'PHPCompatibility.Constants.RemovedConstants.op_silentRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sa_allRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sa_messagesRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sa_recentRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sa_uidnextRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sa_uidvalidityRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sa_unseenRemoved',
        'PHPCompatibility.Constants.RemovedConstants.se_freeRemoved',
        'PHPCompatibility.Constants.RemovedConstants.se_noprefetchRemoved',
        'PHPCompatibility.Constants.RemovedConstants.se_uidRemoved',
        'PHPCompatibility.Constants.RemovedConstants.so_freeRemoved',
        'PHPCompatibility.Constants.RemovedConstants.so_noserverRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sortarrivalRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sortccRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sortdateRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sortfromRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sortsizeRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sortsubjectRemoved',
        'PHPCompatibility.Constants.RemovedConstants.sorttoRemoved',
        'PHPCompatibility.Constants.RemovedConstants.st_setRemoved',
        'PHPCompatibility.Constants.RemovedConstants.st_silentRemoved',
        'PHPCompatibility.Constants.RemovedConstants.st_uidRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typeapplicationRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typeaudioRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typeimageRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typemessageRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typemodelRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typemultipartRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typeotherRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typetextRemoved',
        'PHPCompatibility.Constants.RemovedConstants.typevideoRemoved',
        'PHPCompatibility.IniDirectives.RemovedIniDirectives.imap_enable_insecure_rshRemoved',
    ];

    /**
     * The 10 distinct sniff ids `src/catalogue_expansion_findings.php` proves — one v0.3.2 mapping each
     * (get_class-no-args, trigger_error(E_USER_ERROR), the four non-canonical casts, the backtick
     * operator, `private(set)`, `protected(set)`, and `new Foo()->bar()`). The two remaining v0.3.2
     * mappings (utf8_encode()/utf8_decode()) need no new fixture: `unmapped_findings.php` already calls
     * both, and they simply move from unmapped to mapped (see $preImapIds below).
     *
     * @var list<string>
     */
    private const CATALOGUE_EXPANSION_SNIFF_IDS = [
        'PHPCompatibility.Keywords.NewKeywords.t_private_setFound',
        'PHPCompatibility.Keywords.NewKeywords.t_protected_setFound',
        'PHPCompatibility.LanguageConstructs.RemovedLanguageConstructs.t_backtickDeprecated',
        'PHPCompatibility.ParameterValues.RemovedGetClassNoArgs.ArgMissing',
        'PHPCompatibility.ParameterValues.RemovedTriggerErrorLevel.Deprecated',
        'PHPCompatibility.Syntax.NewClassMemberAccessWithoutParentheses.Found',
        'PHPCompatibility.TypeCasts.RemovedTypeCasts.binaryDeprecated',
        'PHPCompatibility.TypeCasts.RemovedTypeCasts.booleanDeprecated',
        'PHPCompatibility.TypeCasts.RemovedTypeCasts.doubleDeprecated',
        'PHPCompatibility.TypeCasts.RemovedTypeCasts.integerDeprecated',
    ];

    /**
     * The 25 distinct sniff ids `src/catalogue_expansion_r2_findings.php` proves — one v0.3.3 (round 2)
     * mapping each: the readonly-anonymous-class marker, the five `assert_*` constants plus
     * `assert_options()`, the `E_STRICT` constant, the ten `MYSQLI_REFRESH_*` constants plus
     * `mysqli_kill()`/`mysqli_ping()`/`mysqli_refresh()`, `curl_share_close()`, `finfo_close()`, the
     * dynamic class constant fetch syntax (`C::{$name}`), and the static asymmetric-visibility property
     * marker. That last construct (`public private(set) static string $x`) also reports
     * `Keywords.NewKeywords.t_private_setFound` — an already-mapped `language.asymmetric_property_visibility`
     * finding, not one of these 25 — see the second-order accounting below.
     *
     * @var list<string>
     */
    private const CATALOGUE_EXPANSION_R2_SNIFF_IDS = [
        'PHPCompatibility.Classes.NewReadonlyClasses.AnonClass',
        'PHPCompatibility.Classes.NewStaticAvizProperties.Found',
        'PHPCompatibility.Constants.RemovedConstants.assert_activeDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.assert_bailDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.assert_callbackDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.assert_exceptionDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.assert_warningDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.e_strictDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_backup_logDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_grantDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_hostsDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_logDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_masterDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_replicaDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_slaveDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_statusDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_tablesDeprecated',
        'PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_threadsDeprecated',
        'PHPCompatibility.FunctionUse.RemovedFunctions.assert_optionsDeprecated',
        'PHPCompatibility.FunctionUse.RemovedFunctions.curl_share_closeDeprecated',
        'PHPCompatibility.FunctionUse.RemovedFunctions.finfo_closeDeprecated',
        'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_killDeprecated',
        'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_pingDeprecated',
        'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_refreshDeprecated',
        'PHPCompatibility.Syntax.NewDynamicClassConstantFetch.Found',
    ];

    /**
     * The 8 distinct sniff ids `src/catalogue_expansion_r4_findings.php` proves — one v0.3.5 (round 4,
     * finishing Tier A) mapping each: the underscore class-name marker, the DATE_RFC7231 constant, the
     * new request_parse_body() function, the get_defined_functions() $exclude_disabled parameter, the
     * mysqli_store_result() $mode parameter, lcg_value(), and the register_argc_argv / report_memleaks
     * INI directives. The mysqli_store_result() line deliberately passes a literal $mode value (0) so it
     * trips only this parameter sniff, never the constant sniff
     * (Constants.RemovedConstants.mysqli_store_result_copy_dataDeprecated), which WORK-SPEC-R4.md
     * requires stay unmapped because it asserts a "deprecated since PHP 8.1" fact php-src does not
     * support — that sniff never fires here and is absent from every list below.
     *
     * @var list<string>
     */
    private const CATALOGUE_EXPANSION_R4_SNIFF_IDS = [
        'PHPCompatibility.Classes.ForbiddenClassNameUnderscore.Deprecated',
        'PHPCompatibility.Constants.RemovedConstants.date_rfc7231Deprecated',
        'PHPCompatibility.FunctionUse.NewFunctions.request_parse_bodyFound',
        'PHPCompatibility.FunctionUse.RemovedFunctionParameters.get_defined_functions_exclude_disabledDeprecated',
        'PHPCompatibility.FunctionUse.RemovedFunctionParameters.mysqli_store_result_modeDeprecated',
        'PHPCompatibility.FunctionUse.RemovedFunctions.lcg_valueDeprecated',
        'PHPCompatibility.IniDirectives.RemovedIniDirectives.register_argc_argvDeprecated',
        'PHPCompatibility.IniDirectives.RemovedIniDirectives.report_memleaksDeprecated',
    ];

    private string $executable = '';

    private string $findingsProject = '';

    private string $cleanProject = '';

    /** @return iterable<string, array{string, string, ?string, string}> */
    public static function schemaValidationCases(): iterable
    {
        yield 'success: the clean project' => [self::CLEAN_PROJECT_PATH, 'real', null, 'success'];
        yield 'findings: the committed findings tree' => [self::FINDINGS_PROJECT_PATH, 'real', null, 'findings'];
        yield 'unavailable: a missing executable' => [self::FINDINGS_PROJECT_PATH, 'missing', null, 'unavailable'];
        yield 'unavailable: an existing non-phpcs executable' => [
            self::FINDINGS_PROJECT_PATH, 'non-phpcs', null, 'unavailable',
        ];
        yield 'unsupported policy: a non-contiguous allowed set' => [
            self::OR_CONSTRAINT_PROJECT_PATH, 'real', null, 'unsupported_policy',
        ];
    }

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The pinned analyzer used by this group targets POSIX hosts only.');
        }

        if (!NativeProcessRunner::isSupportedOnCurrentPlatform()) {
            self::markTestSkipped(
                'This case executes a child process through the core executor, which requires operational '
                . 'Linux user/PID-namespace isolation.',
            );
        }

        $executable = getenv('MODERN_PHP_GUIDELINES_PHPCS');
        if (!is_string($executable)
            || $executable === ''
            || !is_file($executable)
            || !is_executable($executable)) {
            self::markTestSkipped(
                'MODERN_PHP_GUIDELINES_PHPCS must name an existing, executable, pinned PHP_CodeSniffer binary.',
            );
        }
        $this->executable = $executable;

        $this->findingsProject = self::realProjectRoot(self::FINDINGS_PROJECT_PATH);
        $this->cleanProject = self::realProjectRoot(self::CLEAN_PROJECT_PATH);
    }

    public function testFindingsPathIsCompleteDeterministicAndMapped(): void
    {
        $before = FixtureTreeSnapshot::capture($this->findingsProject);

        [$exitCode, $display] = $this->runForDisplay($this->findingsProject);
        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $exitCode);
        self::assertStringNotContainsString($this->findingsProject, $display);
        self::assertStringNotContainsString($this->executable, $display);

        /**
         * @var array{
         *     status: string,
         *     adapter: array{tool_version: string|null},
         *     summary: array{
         *         invocation_count: int,
         *         finding_count: int,
         *         mapped_finding_count: int,
         *         unmapped_finding_count: int,
         *         mapping_count: int,
         *         mapped_rule_count: int,
         *     },
         *     rule_contexts: list<array{id: string}>,
         *     findings: list<array{external_rule_id: string, file: string|null, mapping_status: string, mapped_rule_ids: list<string>}>,
         * } $report
         */
        $report = self::decode($display);

        self::assertSame('findings', $report['status']);
        self::assertSame(
            [
                'invocation_count' => 3,
                'finding_count' => 211,
                'mapped_finding_count' => 208,
                'unmapped_finding_count' => 3,
                'mapping_count' => 208,
                'mapped_rule_count' => 32,
            ],
            $report['summary'],
        );

        $ids = [];
        foreach ($report['findings'] as $finding) {
            $ids[] = $finding['external_rule_id'];
        }

        // The 16 ids verified before the imap family was mapped, unchanged as a list. Their mapped/
        // unmapped split is no longer 13/3, though: utf8_decodeDeprecated and utf8_encodeDeprecated are
        // now mapped to core.utf8_encode_decode (a v0.3.2 mapping), so this list is 15 mapped + 1
        // unmapped (split() alone stays unmapped, still proving the preservation boundary).
        $preImapIds = [
            'PHPCompatibility.Classes.NewTypedConstants.Found',
            'PHPCompatibility.FunctionDeclarations.RemovedImplicitlyNullableParam.Deprecated',
            'PHPCompatibility.FunctionUse.NewFunctions.array_allFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_anyFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_findFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_find_keyFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_firstFound',
            'PHPCompatibility.FunctionUse.NewFunctions.array_lastFound',
            'PHPCompatibility.FunctionUse.NewFunctions.json_validateFound',
            'PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated',
            'PHPCompatibility.FunctionUse.RemovedFunctions.splitDeprecatedRemoved',
            'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_decodeDeprecated',
            'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_encodeDeprecated',
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.mysqli_reconnectRemoved',
            'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax',
            'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax',
        ];

        // The imap family adds 145 mapped ids (the complete per-fact map returned by
        // imapUnbundledSniffIds(): 75 FunctionUse.RemovedFunctions + 68 Constants.RemovedConstants + 1
        // Classes.RemovedClasses + 1 IniDirectives.RemovedIniDirectives) plus two unmapped ids that prove
        // the boundary: imap_is_open() was itself added in PHP 8.2.1, so it also reports an unrelated
        // "not present in PHP version 8.2.0 or earlier" finding, and imap_header() reports a distinct,
        // pre-floor "removed since PHP 8.0" fact that must not be swept into the map. The v0.3.2
        // catalogue expansion then adds CATALOGUE_EXPANSION_SNIFF_IDS's 10 further mapped ids, proved by
        // catalogue_expansion_findings.php (the other 2 of the 12 new mappings are the utf8 ids already
        // present in $preImapIds above). The v0.3.3 (round 2) catalogue expansion then adds
        // CATALOGUE_EXPANSION_R2_SNIFF_IDS's 25 further mapped ids, proved by
        // catalogue_expansion_r2_findings.php. The v0.3.5 (round 4, finishing Tier A) catalogue expansion
        // then adds CATALOGUE_EXPANSION_R4_SNIFF_IDS's 8 further mapped ids, proved by
        // catalogue_expansion_r4_findings.php.
        $expectedIds = array_merge(
            $preImapIds,
            self::imapUnbundledSniffIds(),
            self::CATALOGUE_EXPANSION_SNIFF_IDS,
            self::CATALOGUE_EXPANSION_R2_SNIFF_IDS,
            self::CATALOGUE_EXPANSION_R4_SNIFF_IDS,
            [
                'PHPCompatibility.FunctionUse.NewFunctions.imap_is_openFound',
                'PHPCompatibility.FunctionUse.RemovedFunctions.imap_headerRemoved',
            ],
        );
        sort($expectedIds, SORT_STRING);

        // (a) the sorted set of external rule ids: 206 distinct ids (203 mapped + 3 unmapped).
        $sortedSet = array_values(array_unique($ids));
        sort($sortedSet, SORT_STRING);
        self::assertSame($expectedIds, $sortedSet);

        // (b) the sorted 211-element multiset: the same 206 distinct ids with five ids each appearing
        // once more, because each is triggered from two distinct locations (or tokens) that dedupe must
        // not collapse: the dollar-brace expression syntax id (once from mapped_findings.php, once from
        // duplicate_findings.php — different files, so different sortKey()s), the IMAP\Connection class
        // id (the parameter type and the return type of the same function signature, in
        // imap_all_surfaces.php — same file and line, different columns), the imap.enable_insecure_rsh
        // ini-directive id (ini_set() then ini_get(), in imap_ini.php — same file, different lines), the
        // backtick operator id (the opening and closing backtick of the one expression in
        // catalogue_expansion_findings.php — same file and line, different columns), and the
        // `private(set)` keyword id (once from catalogue_expansion_findings.php's instance property,
        // once more from catalogue_expansion_r2_findings.php's static property — different files, and a
        // legitimate second-order finding of the round-2 static-asymmetric-visibility construct rather
        // than anything this round deliberately triggered twice). catalogue_expansion_r4_findings.php
        // adds no sixth duplicate: every one of its 8 constructs is a single occurrence of a distinct,
        // previously-unseen sniff id, confirmed by probing it in isolation before it was written.
        $expectedMultiset = $expectedIds;
        $expectedMultiset[] = 'PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax';
        $expectedMultiset[] = 'PHPCompatibility.Classes.RemovedClasses.imap_connectionRemoved';
        $expectedMultiset[] = 'PHPCompatibility.IniDirectives.RemovedIniDirectives.imap_enable_insecure_rshRemoved';
        $expectedMultiset[] = 'PHPCompatibility.LanguageConstructs.RemovedLanguageConstructs.t_backtickDeprecated';
        $expectedMultiset[] = 'PHPCompatibility.Keywords.NewKeywords.t_private_setFound';
        sort($expectedMultiset, SORT_STRING);
        $sortedMultiset = $ids;
        sort($sortedMultiset, SORT_STRING);
        self::assertSame($expectedMultiset, $sortedMultiset);

        /** @var array<string, list<list<string>>> $mappedRuleIdsBySniffId */
        $mappedRuleIdsBySniffId = [];
        foreach ($report['findings'] as $finding) {
            $mappedRuleIdsBySniffId[$finding['external_rule_id']][] = $finding['mapped_rule_ids'];
        }

        foreach (['array_findFound', 'array_find_keyFound', 'array_anyFound', 'array_allFound'] as $suffix) {
            foreach ($mappedRuleIdsBySniffId['PHPCompatibility.FunctionUse.NewFunctions.' . $suffix] as $mapped) {
                self::assertSame(['core.array_find_functions'], $mapped);
            }
        }
        foreach (['array_firstFound', 'array_lastFound'] as $suffix) {
            foreach ($mappedRuleIdsBySniffId['PHPCompatibility.FunctionUse.NewFunctions.' . $suffix] as $mapped) {
                self::assertSame(['core.array_first_last'], $mapped);
            }
        }
        foreach (
            $mappedRuleIdsBySniffId['PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedExpressionSyntax'] as $mapped
        ) {
            self::assertSame(['language.dollar_brace_string_interpolation'], $mapped);
        }
        foreach (
            $mappedRuleIdsBySniffId['PHPCompatibility.TextStrings.RemovedDollarBraceStringEmbeds.DeprecatedVariableSyntax'] as $mapped
        ) {
            self::assertSame(['language.dollar_brace_string_interpolation'], $mapped);
        }
        foreach (self::imapUnbundledSniffIds() as $sniffId) {
            foreach ($mappedRuleIdsBySniffId[$sniffId] as $mapped) {
                self::assertSame(['extension.imap_unbundled'], $mapped);
            }
        }

        // Every one of the 12 v0.3.2 mappings, proved end-to-end through the real analyzer: the two utf8
        // ids (already called by unmapped_findings.php, now mapped instead of unmapped) and the 10
        // CATALOGUE_EXPANSION_SNIFF_IDS ids (proved by catalogue_expansion_findings.php).
        foreach ([
            'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_decodeDeprecated' => ['core.utf8_encode_decode'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.utf8_encodeDeprecated' => ['core.utf8_encode_decode'],
            'PHPCompatibility.Keywords.NewKeywords.t_private_setFound' => ['language.asymmetric_property_visibility'],
            'PHPCompatibility.Keywords.NewKeywords.t_protected_setFound' => ['language.asymmetric_property_visibility'],
            'PHPCompatibility.LanguageConstructs.RemovedLanguageConstructs.t_backtickDeprecated' => ['language.backtick_shell_exec'],
            'PHPCompatibility.ParameterValues.RemovedGetClassNoArgs.ArgMissing' => ['core.get_class_without_arguments'],
            'PHPCompatibility.ParameterValues.RemovedTriggerErrorLevel.Deprecated' => ['core.trigger_error_e_user_error'],
            'PHPCompatibility.Syntax.NewClassMemberAccessWithoutParentheses.Found' => ['language.new_without_parentheses'],
            'PHPCompatibility.TypeCasts.RemovedTypeCasts.binaryDeprecated' => ['language.non_canonical_cast_names'],
            'PHPCompatibility.TypeCasts.RemovedTypeCasts.booleanDeprecated' => ['language.non_canonical_cast_names'],
            'PHPCompatibility.TypeCasts.RemovedTypeCasts.doubleDeprecated' => ['language.non_canonical_cast_names'],
            'PHPCompatibility.TypeCasts.RemovedTypeCasts.integerDeprecated' => ['language.non_canonical_cast_names'],
        ] as $sniffId => $expectedRuleIds) {
            foreach ($mappedRuleIdsBySniffId[$sniffId] as $mapped) {
                self::assertSame($expectedRuleIds, $mapped, $sniffId);
            }
        }

        // Every one of the 25 v0.3.3 (round 2) mappings, proved end-to-end through the real analyzer by
        // catalogue_expansion_r2_findings.php.
        foreach ([
            'PHPCompatibility.Classes.NewReadonlyClasses.AnonClass' => ['language.readonly_anonymous_classes'],
            'PHPCompatibility.Classes.NewStaticAvizProperties.Found' => ['language.static_asymmetric_visibility'],
            'PHPCompatibility.Constants.RemovedConstants.assert_activeDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.assert_bailDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.assert_callbackDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.assert_exceptionDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.assert_warningDeprecated' => ['core.assert_options'],
            'PHPCompatibility.Constants.RemovedConstants.e_strictDeprecated' => ['core.e_strict_constant'],
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
            'PHPCompatibility.FunctionUse.RemovedFunctions.assert_optionsDeprecated' => ['core.assert_options'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.curl_share_closeDeprecated' => ['extension.curl_share_close'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.finfo_closeDeprecated' => ['extension.finfo_close'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_killDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_pingDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_refreshDeprecated' => ['extension.mysqli_ping_kill_refresh'],
            'PHPCompatibility.Syntax.NewDynamicClassConstantFetch.Found' => ['language.dynamic_class_constant_fetch'],
        ] as $sniffId => $expectedRuleIds) {
            foreach ($mappedRuleIdsBySniffId[$sniffId] as $mapped) {
                self::assertSame($expectedRuleIds, $mapped, $sniffId);
            }
        }

        // Every one of the 8 v0.3.5 (round 4, finishing Tier A) mappings, proved end-to-end through the
        // real analyzer by catalogue_expansion_r4_findings.php. mysqli_store_result_modeDeprecated maps
        // to extension.mysqli_store_result_mode alone: the companion constant sniff
        // (Constants.RemovedConstants.mysqli_store_result_copy_dataDeprecated) is deliberately unmapped
        // and never appears as a key here, because it asserts a "since PHP 8.1" fact that disagrees with
        // php-src (see WORK-SPEC-R4.md and the rule's own notes).
        foreach ([
            'PHPCompatibility.Classes.ForbiddenClassNameUnderscore.Deprecated' => ['core.underscore_class_name'],
            'PHPCompatibility.Constants.RemovedConstants.date_rfc7231Deprecated' => ['core.date_rfc7231'],
            'PHPCompatibility.FunctionUse.NewFunctions.request_parse_bodyFound' => ['core.request_parse_body'],
            'PHPCompatibility.FunctionUse.RemovedFunctionParameters.get_defined_functions_exclude_disabledDeprecated' => ['core.get_defined_functions_exclude_disabled'],
            'PHPCompatibility.FunctionUse.RemovedFunctionParameters.mysqli_store_result_modeDeprecated' => ['extension.mysqli_store_result_mode'],
            'PHPCompatibility.FunctionUse.RemovedFunctions.lcg_valueDeprecated' => ['core.lcg_value'],
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.register_argc_argvDeprecated' => ['core.register_argc_argv_ini'],
            'PHPCompatibility.IniDirectives.RemovedIniDirectives.report_memleaksDeprecated' => ['core.report_memleaks_ini'],
        ] as $sniffId => $expectedRuleIds) {
            foreach ($mappedRuleIdsBySniffId[$sniffId] as $mapped) {
                self::assertSame($expectedRuleIds, $mapped, $sniffId);
            }
        }

        self::assertArrayNotHasKey(
            'PHPCompatibility.Constants.RemovedConstants.mysqli_store_result_copy_dataDeprecated',
            $mappedRuleIdsBySniffId,
            'mysqli_store_result_copy_dataDeprecated must stay unmapped and must never fire in this fixture tree.',
        );

        // The static-asymmetric-visibility construct's legitimate second-order finding: still mapped to
        // the round-1 instance-property rule, never to language.static_asymmetric_visibility itself
        // (WORK-SPEC-R2.md's "critical for rule 8").
        foreach ($mappedRuleIdsBySniffId['PHPCompatibility.Keywords.NewKeywords.t_private_setFound'] as $mapped) {
            self::assertSame(['language.asymmetric_property_visibility'], $mapped);
        }

        // The two imap boundary ids prove the "must stay unmapped" decision: imap_is_open() was itself
        // added in PHP 8.2.1 (a fact this catalogue does not track) and imap_header() was removed in PHP
        // 8.0 (a different, pre-floor fact) — neither is part of the 75-entry unbundling family.
        foreach ($mappedRuleIdsBySniffId['PHPCompatibility.FunctionUse.NewFunctions.imap_is_openFound'] as $mapped) {
            self::assertSame([], $mapped);
        }
        foreach ($mappedRuleIdsBySniffId['PHPCompatibility.FunctionUse.RemovedFunctions.imap_headerRemoved'] as $mapped) {
            self::assertSame([], $mapped);
        }

        foreach ($report['findings'] as $finding) {
            if ($finding['mapping_status'] === 'unmapped') {
                self::assertSame([], $finding['mapped_rule_ids']);
            }

            $file = $finding['file'];
            self::assertIsString($file);
            self::assertStringStartsWith('src/', $file);
        }

        $ruleContextIds = [];
        foreach ($report['rule_contexts'] as $context) {
            $ruleContextIds[] = $context['id'];
        }
        self::assertSame(
            [
                'core.array_find_functions',
                'core.array_first_last',
                'core.assert_options',
                'core.date_rfc7231',
                'core.e_strict_constant',
                'core.get_class_without_arguments',
                'core.get_defined_functions_exclude_disabled',
                'core.json_validate',
                'core.lcg_value',
                'core.register_argc_argv_ini',
                'core.report_memleaks_ini',
                'core.request_parse_body',
                'core.trigger_error_e_user_error',
                'core.underscore_class_name',
                'core.utf8_encode_decode',
                'extension.curl_close',
                'extension.curl_share_close',
                'extension.finfo_close',
                'extension.imap_unbundled',
                'extension.mysqli_driver_reconnect',
                'extension.mysqli_ping_kill_refresh',
                'extension.mysqli_store_result_mode',
                'language.asymmetric_property_visibility',
                'language.backtick_shell_exec',
                'language.dollar_brace_string_interpolation',
                'language.dynamic_class_constant_fetch',
                'language.implicitly_nullable_parameter_types',
                'language.new_without_parentheses',
                'language.non_canonical_cast_names',
                'language.readonly_anonymous_classes',
                'language.static_asymmetric_visibility',
                'language.typed_class_constants',
            ],
            $ruleContextIds,
        );

        $toolVersion = $report['adapter']['tool_version'];
        self::assertIsString($toolVersion);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $toolVersion);

        self::assertSame($before, FixtureTreeSnapshot::capture($this->findingsProject));
    }

    public function testCleanProjectCompletesWithoutFindings(): void
    {
        $before = FixtureTreeSnapshot::capture($this->cleanProject);

        [$exitCode, $report] = $this->verifyReport($this->cleanProject);
        /**
         * @var array{
         *     status: string,
         *     invocations: list<array{status: string, exit_code: int|null}>,
         *     summary: array{finding_count: int},
         * } $report
         */
        self::assertSame(ExitCode::SUCCESS, $exitCode);
        self::assertSame('success', $report['status']);
        self::assertSame(0, $report['summary']['finding_count']);
        self::assertCount(3, $report['invocations']);
        foreach ($report['invocations'] as $invocation) {
            self::assertSame('exited', $invocation['status']);
            self::assertSame(0, $invocation['exit_code']);
        }

        self::assertSame($before, FixtureTreeSnapshot::capture($this->cleanProject));
    }

    public function testNarrowerPolicyChangesTheProjectionAndTheFindings(): void
    {
        [, $narrow] = $this->verifyReport($this->findingsProject, '8.5.*');
        /**
         * @var array{
         *     policy: array{planned_invocations: list<array{arguments: list<string>}>},
         *     findings: list<array{external_rule_id: string}>,
         * } $narrow
         */
        self::assertSame('8.5', $narrow['policy']['planned_invocations'][2]['arguments'][3]);
        $narrowIds = [];
        foreach ($narrow['findings'] as $finding) {
            $narrowIds[] = $finding['external_rule_id'];
        }
        self::assertContains('PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated', $narrowIds);
        foreach ($narrowIds as $id) {
            self::assertStringNotContainsString('NewFunctions', $id);
        }

        [, $wide] = $this->verifyReport($this->findingsProject, '>=8.2 <8.5');
        /**
         * @var array{
         *     policy: array{planned_invocations: list<array{arguments: list<string>}>},
         *     findings: list<array{external_rule_id: string}>,
         * } $wide
         */
        self::assertSame('8.2-8.4', $wide['policy']['planned_invocations'][2]['arguments'][3]);
        $wideIds = [];
        foreach ($wide['findings'] as $finding) {
            $wideIds[] = $finding['external_rule_id'];
        }
        self::assertNotContains('PHPCompatibility.FunctionUse.RemovedFunctions.curl_closeDeprecated', $wideIds);
        self::assertContains('PHPCompatibility.FunctionUse.NewFunctions.array_findFound', $wideIds);

        // The two projections above are driven entirely by the --php override, never by the PHP version
        // the CLI process itself happens to run under: this is the "local runtime is never substituted"
        // proof, alongside the two-axis proof that a narrower or wider --php changes both the projected
        // argv and the resulting findings.
    }

    public function testUnsupportedPolicyNeverRunsTheAnalyzer(): void
    {
        $projectRoot = self::realProjectRoot(self::OR_CONSTRAINT_PROJECT_PATH);
        $before = FixtureTreeSnapshot::capture($projectRoot);

        [$exitCode, $report] = $this->verifyReport($projectRoot);
        /**
         * @var array{
         *     status: string,
         *     reason: array{code: string},
         *     invocations: list<mixed>,
         *     policy: array{planned_invocations: list<mixed>},
         * } $report
         */
        self::assertSame(ExitCode::POLICY_PROJECTION_UNSUPPORTED, $exitCode);
        self::assertSame('unsupported_policy', $report['status']);
        self::assertSame(VerificationReason::POLICY_PROJECTION_UNSUPPORTED, $report['reason']['code']);
        self::assertSame([], $report['invocations']);
        self::assertSame([], $report['policy']['planned_invocations']);

        self::assertSame($before, FixtureTreeSnapshot::capture($projectRoot));
    }

    public function testMissingExecutableIsUnavailable(): void
    {
        $before = FixtureTreeSnapshot::capture($this->findingsProject);

        [$exitCode, $report] = $this->verifyReport(
            $this->findingsProject,
            null,
            '/definitely/not-installed/phpcs',
        );
        /**
         * @var array{
         *     status: string,
         *     adapter: array{executable: string},
         *     policy: array{projection_status: string},
         *     reason: array{code: string},
         * } $report
         */
        self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $exitCode);
        self::assertSame('unavailable', $report['status']);
        self::assertSame('<external>/phpcs', $report['adapter']['executable']);
        self::assertSame('not_evaluated', $report['policy']['projection_status']);
        self::assertSame(VerificationReason::EXECUTABLE_UNAVAILABLE, $report['reason']['code']);

        self::assertSame($before, FixtureTreeSnapshot::capture($this->findingsProject));
    }

    public function testExistingNonPhpcsExecutableIsCapabilityUnavailable(): void
    {
        $before = FixtureTreeSnapshot::capture($this->findingsProject);

        [$exitCode, $report] = $this->verifyReport(
            $this->findingsProject,
            null,
            self::existingNonPhpcsExecutable(),
        );
        /**
         * @var array{
         *     status: string,
         *     adapter: array{tool_version: string|null},
         *     reason: array{code: string},
         *     invocations: list<array{status: string}>,
         * } $report
         */
        self::assertSame(ExitCode::ADAPTER_UNAVAILABLE, $exitCode);
        self::assertSame('unavailable', $report['status']);
        self::assertSame(VerificationReason::CAPABILITY_UNAVAILABLE, $report['reason']['code']);
        self::assertCount(1, $report['invocations']);
        self::assertSame('exited', $report['invocations'][0]['status']);
        self::assertNull($report['adapter']['tool_version']);

        self::assertSame($before, FixtureTreeSnapshot::capture($this->findingsProject));
    }

    public function testHumanAndJsonReportTheSameStatusAndCounts(): void
    {
        $humanTester = self::tester();
        $humanExitCode = $humanTester->run(
            [
                'command' => 'verify',
                'adapter' => 'phpcompatibility',
                '--executable' => $this->executable,
                '--project-root' => $this->findingsProject,
            ],
            ['capture_stderr_separately' => true, 'decorated' => false],
        );
        $human = $humanTester->getDisplay();

        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $humanExitCode);
        self::assertStringContainsString('Verification: findings (exit 6)', $human);
        self::assertStringContainsString('Planned invocations: 3', $human);
        self::assertStringContainsString('Invocations: 3', $human);
        self::assertStringContainsString('Findings: 211', $human);
        self::assertStringContainsString('mapped findings        208', $human);
        self::assertStringContainsString('unmapped findings      3', $human);

        [$jsonExitCode, $report] = $this->verifyReport($this->findingsProject);
        /** @var array{status: string, exit_code: int, summary: array{finding_count: int}} $report */
        self::assertSame($humanExitCode, $jsonExitCode);
        self::assertSame('findings', $report['status']);
        self::assertSame(ExitCode::VERIFICATION_FINDINGS, $report['exit_code']);
        self::assertSame(211, $report['summary']['finding_count']);
    }

    public function testJsonOutputIsByteIdenticalAcrossTwoRuns(): void
    {
        $before = FixtureTreeSnapshot::capture($this->findingsProject);

        [, $firstDisplay] = $this->runForDisplay($this->findingsProject);
        [, $secondDisplay] = $this->runForDisplay($this->findingsProject);

        self::assertSame($firstDisplay, $secondDisplay);
        self::assertSame($before, FixtureTreeSnapshot::capture($this->findingsProject));
    }

    #[DataProvider('schemaValidationCases')]
    public function testSchemaValidatesEveryRealAnalyzerOutcome(
        string $projectRoot,
        string $executableSelector,
        ?string $phpOverride,
        string $expectedStatus,
    ): void {
        $executable = match ($executableSelector) {
            'real' => $this->executable,
            'missing' => '/definitely/not-installed/phpcs',
            'non-phpcs' => self::existingNonPhpcsExecutable(),
            default => self::fail('Unknown executable selector: ' . $executableSelector),
        };

        [, $display] = $this->runForDisplay(self::realProjectRoot($projectRoot), $phpOverride, $executable);

        $tree = json_decode($display, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $tree);
        self::assertSame([], (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($tree));

        /** @var array{status: string} $report */
        $report = self::decode($display);
        self::assertSame($expectedStatus, $report['status']);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function verifyReport(string $projectRoot, ?string $phpOverride = null, ?string $executable = null): array
    {
        [$exitCode, $display] = $this->runForDisplay($projectRoot, $phpOverride, $executable);

        return [$exitCode, self::decode($display)];
    }

    /** @return array{0: int, 1: string} */
    private function runForDisplay(string $projectRoot, ?string $phpOverride = null, ?string $executable = null): array
    {
        /** @var array<string, bool|string> $arguments */
        $arguments = [
            'command' => 'verify',
            'adapter' => 'phpcompatibility',
            '--executable' => $executable ?? $this->executable,
            '--project-root' => $projectRoot,
            '--json' => true,
        ];
        if ($phpOverride !== null) {
            $arguments['--php'] = $phpOverride;
        }

        $tester = self::tester();
        $exitCode = $tester->run($arguments, ['capture_stderr_separately' => true, 'decorated' => false]);

        self::assertSame('', $tester->getErrorOutput());
        $display = $tester->getDisplay();
        self::assertStringEndsWith("\n", $display);

        $tree = json_decode($display, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $tree);
        self::assertSame([], (new JsonSchemaValidator(PackagePaths::verificationSchemaPath()))->validate($tree));

        return [$exitCode, $display];
    }

    /** @return array<string, mixed> */
    private static function decode(string $display): array
    {
        /** @var array<string, mixed> $report */
        $report = json_decode($display, true, 512, JSON_THROW_ON_ERROR);

        return $report;
    }

    private static function tester(): ApplicationTester
    {
        $application = ApplicationFactory::create();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return new ApplicationTester($application);
    }

    private static function existingNonPhpcsExecutable(): string
    {
        foreach (['/usr/bin/true', '/bin/true'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        self::fail('Neither /usr/bin/true nor /bin/true exists on this host.');
    }

    private static function realProjectRoot(string $path): string
    {
        $resolved = realpath($path);
        self::assertIsString($resolved);

        return $resolved;
    }

    /**
     * @return list<string> the complete 145-id per-fact map: the 75 `…RemovedFunctions.<name>Removed`
     *     sniff ids IMAP_UNBUNDLED_FUNCTION_NAMES calls into, plus the 70 ids in
     *     IMAP_UNBUNDLED_NON_FUNCTION_SNIFF_IDS.
     */
    private static function imapUnbundledSniffIds(): array
    {
        return array_merge(
            array_map(
                static fn(string $name): string => 'PHPCompatibility.FunctionUse.RemovedFunctions.' . $name . 'Removed',
                self::IMAP_UNBUNDLED_FUNCTION_NAMES,
            ),
            self::IMAP_UNBUNDLED_NON_FUNCTION_SNIFF_IDS,
        );
    }
}

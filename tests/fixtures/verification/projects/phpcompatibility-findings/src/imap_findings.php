<?php

declare(strict_types=1);

/**
 * ext/imap was unbundled from php-src in PHP 8.4 (moved to PECL). PHPCompatibility reports that one
 * fact through four distinct sniff families, and this file proves only one of them:
 * FunctionUse.RemovedFunctions reports every function below as "removed since PHP 8.4" for exactly
 * that reason, and this is the complete set of imap_* function ids sharing it (75 ids, cross-checked
 * against phpcompatibility/php-compatibility 10.0.0-alpha2's own removedFunctions table).
 *
 * The other three families reporting the same fact are proved by sibling fixtures, not by this file:
 * `imap_all_surfaces.php` covers Constants.RemovedConstants (68 ids) and Classes.RemovedClasses (1 id,
 * IMAP\Connection); `imap_ini.php` covers IniDirectives.RemovedIniDirectives (1 id,
 * imap.enable_insecure_rsh). Together the four files prove the complete 145-id per-fact map
 * (75 + 68 + 1 + 1), not an arbitrary subset of it.
 */
function imap_unbundled_functions(): void
{
    imap_8bit();
    imap_alerts();
    imap_append();
    imap_base64();
    imap_binary();
    imap_body();
    imap_bodystruct();
    imap_check();
    imap_clearflag_full();
    imap_close();
    imap_create();
    imap_createmailbox();
    imap_delete();
    imap_deletemailbox();
    imap_errors();
    imap_expunge();
    imap_fetch_overview();
    imap_fetchbody();
    imap_fetchheader();
    imap_fetchmime();
    imap_fetchstructure();
    imap_fetchtext();
    imap_gc();
    imap_get_quota();
    imap_get_quotaroot();
    imap_getacl();
    imap_getmailboxes();
    imap_getsubscribed();
    imap_headerinfo();
    imap_headers();
    // imap_is_open() was itself added in PHP 8.2.1 (a patch release), independently of the 8.4
    // unbundling; on a floor of PHP 8.2 (8.2.0) this also reports a genuine, unrelated, and
    // deliberately unmapped "not present in PHP version 8.2.0 or earlier" finding alongside its
    // own mapped "removed since PHP 8.4" finding below.
    imap_is_open();
    imap_last_error();
    imap_list();
    imap_listmailbox();
    imap_listscan();
    imap_listsubscribed();
    imap_lsub();
    imap_mail();
    imap_mail_compose();
    imap_mail_copy();
    imap_mail_move();
    imap_mailboxmsginfo();
    imap_mime_header_decode();
    imap_msgno();
    imap_mutf7_to_utf8();
    imap_num_msg();
    imap_num_recent();
    imap_open();
    imap_ping();
    imap_qprint();
    imap_rename();
    imap_renamemailbox();
    imap_reopen();
    imap_rfc822_parse_adrlist();
    imap_rfc822_parse_headers();
    imap_rfc822_write_address();
    imap_savebody();
    imap_scan();
    imap_scanmailbox();
    imap_search();
    imap_set_quota();
    imap_setacl();
    imap_setflag_full();
    imap_sort();
    imap_status();
    imap_subscribe();
    imap_thread();
    imap_timeout();
    imap_uid();
    imap_undelete();
    imap_unsubscribe();
    imap_utf7_decode();
    imap_utf7_encode();
    imap_utf8();
    imap_utf8_to_mutf7();
}

/**
 * imap_header() is a distinct fact: it was removed in PHP 8.0, superseded by imap_headerinfo(),
 * before this tool's PHP floor and unrelated to the PHP 8.4 extension unbundling above. It must
 * stay unmapped, and is here to prove the boundary is drawn correctly rather than assumed.
 */
function imap_removed_before_floor(): void
{
    imap_header();
}

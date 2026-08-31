<?php

declare(strict_types=1);

/**
 * Two of the four sniff families that report the "ext/imap removed in PHP 8.4" fact (see
 * imap_findings.php for the FunctionUse.RemovedFunctions family and imap_ini.php for the fourth):
 * all 68 ext/imap constants the pinned alpha2 table marks removed in PHP 8.4, plus the IMAP\Connection
 * class.
 */
function imap_constant_surface(): void
{
    echo CL_EXPUNGE;
    echo CP_MOVE;
    echo CP_UID;
    echo ENC7BIT;
    echo ENC8BIT;
    echo ENCBASE64;
    echo ENCBINARY;
    echo ENCOTHER;
    echo ENCQUOTEDPRINTABLE;
    echo FT_INTERNAL;
    echo FT_NOT;
    echo FT_PEEK;
    echo FT_PREFETCHTEXT;
    echo FT_UID;
    echo IMAP_CLOSETIMEOUT;
    echo IMAP_GC_ELT;
    echo IMAP_GC_ENV;
    echo IMAP_GC_TEXTS;
    echo IMAP_OPENTIMEOUT;
    echo IMAP_READTIMEOUT;
    echo IMAP_WRITETIMEOUT;
    echo LATT_HASCHILDREN;
    echo LATT_HASNOCHILDREN;
    echo LATT_MARKED;
    echo LATT_NOINFERIORS;
    echo LATT_NOSELECT;
    echo LATT_REFERRAL;
    echo LATT_UNMARKED;
    echo NIL;
    echo OP_ANONYMOUS;
    echo OP_DEBUG;
    echo OP_EXPUNGE;
    echo OP_HALFOPEN;
    echo OP_PROTOTYPE;
    echo OP_READONLY;
    echo OP_SECURE;
    echo OP_SHORTCACHE;
    echo OP_SILENT;
    echo SA_ALL;
    echo SA_MESSAGES;
    echo SA_RECENT;
    echo SA_UIDNEXT;
    echo SA_UIDVALIDITY;
    echo SA_UNSEEN;
    echo SE_FREE;
    echo SE_NOPREFETCH;
    echo SE_UID;
    echo SORTARRIVAL;
    echo SORTCC;
    echo SORTDATE;
    echo SORTFROM;
    echo SORTSIZE;
    echo SORTSUBJECT;
    echo SORTTO;
    echo SO_FREE;
    echo SO_NOSERVER;
    echo ST_SET;
    echo ST_SILENT;
    echo ST_UID;
    echo TYPEAPPLICATION;
    echo TYPEAUDIO;
    echo TYPEIMAGE;
    echo TYPEMESSAGE;
    echo TYPEMODEL;
    echo TYPEMULTIPART;
    echo TYPEOTHER;
    echo TYPETEXT;
    echo TYPEVIDEO;
}

function imap_class_surface(\IMAP\Connection $c): \IMAP\Connection
{
    return $c;
}

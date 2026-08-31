<?php

declare(strict_types=1);

/**
 * The fourth of the four sniff families that report the "ext/imap removed in PHP 8.4" fact (see
 * imap_findings.php and imap_all_surfaces.php for the other three): the one ext/imap INI directive the
 * pinned alpha2 table marks removed in PHP 8.4.
 */
function imap_ini_surface(): void
{
    ini_set('imap.enable_insecure_rsh', '0');
    echo ini_get('imap.enable_insecure_rsh');
}

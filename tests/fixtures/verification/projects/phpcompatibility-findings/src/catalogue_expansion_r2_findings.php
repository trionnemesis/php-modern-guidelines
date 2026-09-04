<?php

/**
 * One occurrence of each v0.3.3 (round 2) sniff newly mapped in PhpCompatibilityAdapter::SNIFF_RULE_MAP
 * that this fixture tree does not already trigger elsewhere: the round-2 eight-rule expansion's 25 new
 * sniff ids (WAVE2-R2-BRIEF.md Task 1/3). Probed directly with the CI-pinned analyzer before being
 * written here; every id below is measured, not assumed.
 */
class AnonReadonlyDemo
{
    public static function build(): object
    {
        // PHPCompatibility.Classes.NewReadonlyClasses.AnonClass
        return new readonly class (1) {
            public function __construct(public int $value)
            {
            }
        };
    }
}

function assertConstants(): void
{
    // PHPCompatibility.Constants.RemovedConstants.assert_activeDeprecated
    $activeConst = ASSERT_ACTIVE;
    // PHPCompatibility.Constants.RemovedConstants.assert_bailDeprecated
    $bailConst = ASSERT_BAIL;
    // PHPCompatibility.Constants.RemovedConstants.assert_callbackDeprecated
    $callbackConst = ASSERT_CALLBACK;
    // PHPCompatibility.Constants.RemovedConstants.assert_exceptionDeprecated
    $exceptionConst = ASSERT_EXCEPTION;
    // PHPCompatibility.Constants.RemovedConstants.assert_warningDeprecated
    $warningConst = ASSERT_WARNING;
    echo $activeConst, $bailConst, $callbackConst, $exceptionConst, $warningConst;
}

function errorLevelConstant(): void
{
    // PHPCompatibility.Constants.RemovedConstants.e_strictDeprecated
    $strictConst = E_STRICT;
    echo $strictConst;
}

function mysqliRefreshConstants(): void
{
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_backup_logDeprecated
    $refreshBackupLog = MYSQLI_REFRESH_BACKUP_LOG;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_grantDeprecated
    $refreshGrant = MYSQLI_REFRESH_GRANT;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_hostsDeprecated
    $refreshHosts = MYSQLI_REFRESH_HOSTS;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_logDeprecated
    $refreshLog = MYSQLI_REFRESH_LOG;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_masterDeprecated
    $refreshMaster = MYSQLI_REFRESH_MASTER;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_replicaDeprecated
    $refreshReplica = MYSQLI_REFRESH_REPLICA;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_slaveDeprecated
    $refreshSlave = MYSQLI_REFRESH_SLAVE;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_statusDeprecated
    $refreshStatus = MYSQLI_REFRESH_STATUS;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_tablesDeprecated
    $refreshTables = MYSQLI_REFRESH_TABLES;
    // PHPCompatibility.Constants.RemovedConstants.mysqli_refresh_threadsDeprecated
    $refreshThreads = MYSQLI_REFRESH_THREADS;
    echo $refreshBackupLog, $refreshGrant, $refreshHosts, $refreshLog, $refreshMaster;
    echo $refreshReplica, $refreshSlave, $refreshStatus, $refreshTables, $refreshThreads;
}

function assertOptionsCall(): void
{
    // PHPCompatibility.FunctionUse.RemovedFunctions.assert_optionsDeprecated (a literal option value is
    // used deliberately so this line does not also re-trigger an assert_*Deprecated constant finding
    // already proven above).
    assert_options(1, 1);
}

function curlShareCloseCall(): void
{
    $shareHandle = curl_share_init();
    // PHPCompatibility.FunctionUse.RemovedFunctions.curl_share_closeDeprecated
    curl_share_close($shareHandle);
}

function finfoCloseCall(): void
{
    $finfoHandle = finfo_open();
    // PHPCompatibility.FunctionUse.RemovedFunctions.finfo_closeDeprecated
    finfo_close($finfoHandle);
}

function mysqliPingKillRefreshCalls(): void
{
    $link = mysqli_connect('localhost', 'user', 'pass', 'db');
    // PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_killDeprecated
    mysqli_kill($link, 1);
    // PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_pingDeprecated
    mysqli_ping($link);
    // PHPCompatibility.FunctionUse.RemovedFunctions.mysqli_refreshDeprecated (a literal bitmask value is
    // used deliberately so this line does not also re-trigger a mysqli_refresh_*Deprecated constant
    // finding already proven above).
    mysqli_refresh($link, 2);
}

class DynamicConstHolder
{
    public const NAME = 'value';
}

function dynamicClassConstantFetchDemo(): void
{
    $constName = 'NAME';
    // PHPCompatibility.Syntax.NewDynamicClassConstantFetch.Found
    $dynamicValue = DynamicConstHolder::{$constName};
    echo $dynamicValue;
}

class StaticAvizHolder
{
    // PHPCompatibility.Classes.NewStaticAvizProperties.Found (this line also reports
    // Keywords.NewKeywords.t_private_setFound, a legitimate second-order finding that stays mapped to
    // the already-shipped language.asymmetric_property_visibility rule, not this one).
    public private(set) static string $label = '';
}

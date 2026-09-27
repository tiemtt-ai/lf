<?php

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Metadata-only check that the connected account can create triggers in the
 * current schema, run BEFORE a migration issues any DDL. MariaDB commits each
 * DDL statement, so learning about a missing privilege at CREATE TRIGGER time
 * leaves the tables and columns created before it behind (pre-apply review H2).
 *
 * Fail-closed by design. Only privileges visible for CURRENT_USER() itself in
 * information_schema count: global or schema-level TRIGGER, and SUPER when the
 * binary log is on without log_bin_trust_function_creators. Privileges held
 * only through a role or at table level are not visible there and are treated
 * as absent — grant them to the account directly for the apply. A pass proves
 * the privilege metadata, not that every later statement will succeed; the
 * DEFINER account must also still exist after deployment.
 */
final class TriggerCreationPreflight
{
    public static function assertCanCreateTriggers(ConnectionInterface $db): void
    {
        $server = $db->selectOne('SELECT CURRENT_USER() AS account, DATABASE() AS db, @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trust');
        $grantee = self::grantee((string) $server->account);
        $trigger = (bool) $db->selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = 'TRIGGER')
                OR EXISTS (SELECT 1 FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = 'TRIGGER' AND ? LIKE TABLE_SCHEMA) AS granted",
            [$grantee, $grantee, (string) $server->db],
        )->granted;
        $super = (bool) $db->selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = 'SUPER') AS granted",
            [$grantee],
        )->granted;

        $failure = self::decide((bool) $server->log_bin, (bool) $server->trust, $trigger, $super);
        if ($failure !== null) {
            throw new RuntimeException($failure.': account '.$server->account.' on schema '.$server->db);
        }
    }

    /** Pure decision, so every branch is testable without reconfiguring a server. */
    public static function decide(bool $binaryLog, bool $trustCreators, bool $hasTrigger, bool $hasSuper): ?string
    {
        if (! $hasTrigger) {
            return 'LF_MIGRATION_PREFLIGHT_TRIGGER_PRIVILEGE';
        }
        if ($binaryLog && ! $trustCreators && ! $hasSuper) {
            return 'LF_MIGRATION_PREFLIGHT_BINLOG_TRIGGER';
        }

        return null;
    }

    /** CURRENT_USER() is user@host; information_schema writes 'user'@'host'. */
    private static function grantee(string $account): string
    {
        $at = strrpos($account, '@');
        $user = $at === false ? $account : substr($account, 0, $at);
        $host = $at === false ? '' : substr($account, $at + 1);

        return "'".str_replace("'", "''", $user)."'@'".str_replace("'", "''", $host)."'";
    }
}

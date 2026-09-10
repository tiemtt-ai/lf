<?php

namespace App\Services\Ai;

use App\Contracts\Ai\TenantSettingSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads gate step 2's tenant approval out of `saas_customer_settings`.
 *
 * The table is still `Review / Not Implemented`, so this class checks for it and
 * returns null when it is absent. That is not defensive noise: without the
 * check, every gate call would raise a QueryException instead of producing a
 * clean `blocked` run, turning a fail-closed refusal into an unhandled error.
 * The absent-table branch disappears on its own once the SaaS packet migrates.
 */
final class DatabaseTenantSettings implements TenantSettingSource
{
    private const GROUP = 'ai';

    public function get(int $customerId, string $settingKey): ?array
    {
        if (! Schema::hasTable('saas_customer_settings')) {
            return null;
        }

        $row = DB::table('saas_customer_settings')
            ->where('customer_id', $customerId)
            ->where('setting_group', self::GROUP)
            ->where('setting_key', $settingKey)
            ->first(['setting_value', 'value_type']);

        // Anything but an approved JSON object is "no approval on record". A
        // malformed setting must never read as permission.
        if ($row === null || $row->value_type !== 'json' || ! is_string($row->setting_value)) {
            return null;
        }

        $decoded = json_decode($row->setting_value, true);

        return is_array($decoded) ? $decoded : null;
    }
}

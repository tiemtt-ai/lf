<?php

namespace App\Contracts\Ai;

/**
 * Reads one grouped tenant setting.
 *
 * Split out from the approval logic on purpose: the *matching* rules of gate
 * step 2 are real and testable today, while the *store* they read
 * (`saas_customer_settings`) is still `not_implemented`. Isolating the store
 * behind this port keeps the fail-closed surface to a single class instead of
 * spreading unavailability through the gate.
 */
interface TenantSettingSource
{
    /** @return array<string,mixed>|null Null when no approved setting exists. */
    public function get(int $customerId, string $settingKey): ?array;
}

<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\TenantSettingSource;

/** In-memory stand-in for `saas_customer_settings`, keyed per tenant. */
final class FakeTenantSettings implements TenantSettingSource
{
    /** @var array<int,array<string,array<string,mixed>>> */
    private array $settings = [];

    /** @param array<string,mixed> $value */
    public function approve(int $customerId, string $settingKey, array $value): void
    {
        $this->settings[$customerId][$settingKey] = $value;
    }

    public function get(int $customerId, string $settingKey): ?array
    {
        return $this->settings[$customerId][$settingKey] ?? null;
    }
}

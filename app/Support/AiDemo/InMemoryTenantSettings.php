<?php

namespace App\Support\AiDemo;

use App\Contracts\Ai\TenantSettingSource;

/**
 * Tenant approvals held only for the life of one process.
 *
 * Used by the local demo command so it can pass the provider gate without
 * writing a durable approval: nothing here survives the process, so no
 * environment is ever left approved for a provider.
 */
final class InMemoryTenantSettings implements TenantSettingSource
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

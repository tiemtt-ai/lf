<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\CommercialEntitlements;

final class FakeCommercialEntitlements implements CommercialEntitlements
{
    /** @var array<int,array<string,bool>> */
    private array $granted = [];

    public function grant(int $customerId, string $featureKey): void
    {
        $this->granted[$customerId][$featureKey] = true;
    }

    public function hasActiveEntitlement(int $customerId, string $featureKey): bool
    {
        return $this->granted[$customerId][$featureKey] ?? false;
    }
}

<?php

namespace App\Support;

class Tenant
{
    public static function id(): ?int
    {
        return TenantContext::customerId();
    }

    public static function customer()
    {
        return TenantContext::customer();
    }

    public static function themeKey(): ?string
    {
        return TenantContext::themeKey();
    }

    public static function layoutKey(): ?string
    {
        return TenantContext::layoutKey();
    }
}

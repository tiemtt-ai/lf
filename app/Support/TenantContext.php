<?php

namespace App\Support;

class TenantContext
{
    protected static ?object $customer = null;

    public static function set(?object $customer): void
    {
        self::$customer = $customer;
    }

    public static function customer(): ?object
    {
        return self::$customer;
    }

    public static function customerId(): ?int
    {
        return self::$customer?->id;
    }

    public static function themeKey(): string
    {
        return self::$customer?->theme_key ?? 'default';
    }

    public static function layoutKey(): string
    {
        return self::$customer?->layout_key ?? 'default';
    }

    public static function slug(): ?string
    {
        return self::$customer?->slug;
    }
}

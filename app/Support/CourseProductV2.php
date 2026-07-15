<?php

namespace App\Support;

final class CourseProductV2
{
    public const PACKAGE_SINGLE = 'single_course';

    public const PACKAGE_TYPES = [self::PACKAGE_SINGLE, 'bundle'];

    public const OFFERING_TYPES = [
        'self_paced_course', 'live_online_course', 'blended_course',
        'assessment', 'learning_material',
    ];

    public const STATUSES = ['draft', 'active', 'inactive', 'archived'];

    public const DISCOUNT_TYPES = ['percentage', 'fixed_amount'];

    public const MEDIA_OWNER = 'course_product';

    public const MEDIA_PURPOSES = ['intro_image', 'intro_video', 'intro_document'];

    public static function sellingPrice(string $price, bool $enabled, ?string $type, ?string $value): string
    {
        $amount = self::minorUnits($price);
        if (! $enabled || $value === null) {
            return self::decimal($amount);
        }

        $discount = self::minorUnits($value);
        $result = $type === 'percentage'
            ? $amount - (int) round($amount * $discount / 10000)
            : $amount - $discount;

        return self::decimal(max(0, $result));
    }

    private static function minorUnits(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function decimal(int $minorUnits): string
    {
        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function registrationOpen(object $product, \DateTimeInterface $at): bool
    {
        return $product->status === 'active'
            && (! $product->registration_starts_at || $at >= new \DateTimeImmutable($product->registration_starts_at))
            && (! $product->registration_ends_at || $at < new \DateTimeImmutable($product->registration_ends_at));
    }
}

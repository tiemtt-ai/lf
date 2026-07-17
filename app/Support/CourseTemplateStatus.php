<?php

namespace App\Support;

final class CourseTemplateStatus
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const ARCHIVED = 'archived';

    public const DEFAULT = self::DRAFT;

    public const VALUES = [
        self::DRAFT,
        self::ACTIVE,
        self::INACTIVE,
        self::ARCHIVED,
    ];

    public const EDITABLE_VALUES = [
        self::DRAFT,
        self::ACTIVE,
        self::INACTIVE,
    ];

    public static function canPublish(string $status): bool
    {
        return $status === self::ACTIVE;
    }
}

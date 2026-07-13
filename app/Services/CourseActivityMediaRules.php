<?php

namespace App\Services;

class CourseActivityMediaRules
{
    public const OWNER_TYPE = 'course_activity';

    public const UPLOADED_TYPES = ['video', 'audio', 'document'];

    private const MIMES = [
        'video' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo'],
        'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm', 'audio/mp4'],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
        ],
    ];

    public static function isUploadedType(string $type): bool
    {
        return in_array($type, self::UPLOADED_TYPES, true);
    }

    public static function isCompatible(object $media, string $type): bool
    {
        return self::isUploadedType($type)
            && $media->file_type === $type
            && in_array($media->mime_type, self::MIMES[$type], true);
    }
}

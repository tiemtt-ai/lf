<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SpeechToTextProcessingEligibility
{
    /** Call while holding the Media row lock. */
    public function hasActiveUsage(int $customerId, int $mediaFileId, string $fileType): bool
    {
        $usageType = match ($fileType) {
            'audio' => 'audio',
            'video' => 'video',
            default => null,
        };

        return $usageType !== null && DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('media_file_id', $mediaFileId)
            ->where('owner_type', 'course_activity')
            ->where('usage_type', $usageType)
            ->where('status', 'active')
            ->exists();
    }
}

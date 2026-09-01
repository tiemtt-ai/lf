<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AudioProcessingEligibility
{
    /** Call while holding the Media row lock. */
    public function hasActiveUsage(int $customerId, int $mediaFileId): bool
    {
        return DB::table('media_file_usages')->where('customer_id', $customerId)
            ->where('media_file_id', $mediaFileId)->where('owner_type', 'course_activity')
            ->where('usage_type', 'audio')->where('status', 'active')->exists();
    }
}

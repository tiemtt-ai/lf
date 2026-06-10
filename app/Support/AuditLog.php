<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLog
{
    public static function record(
        Request $request,
        int $customerId,
        string $action,
        ?int $targetUserId,
        ?array $before = null,
        ?array $after = null
    ): void {
        DB::table('saas_audit_logs')->insert([
            'customer_id' => $customerId,
            'actor_id' => $request->user()?->id,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}

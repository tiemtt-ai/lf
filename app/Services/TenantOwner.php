<?php

namespace App\Services;

use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The tenant owner ("Chủ trung tâm", ADR-0033 D2): the one active admin who manages the other admins' accounts and
 * may hand the role over. Rights never depend on who created an account.
 */
final class TenantOwner
{
    /**
     * The owner's user id. A tenant left at NULL (no admin when the column arrived) takes its earliest active admin as
     * soon as it has one (saas_customers § Tenant Owner Amendment).
     */
    public function id(int $customerId): ?int
    {
        $ownerId = DB::table('saas_customers')->where('id', $customerId)->value('owner_user_id');
        if ($ownerId !== null) {
            return (int) $ownerId;
        }
        $first = DB::table('users')->where('customer_id', $customerId)->where('role', 'customer_admin')
            ->where('status', 'active')->orderBy('created_at')->orderBy('id')->value('id');
        if ($first === null) {
            return null;
        }
        DB::table('saas_customers')->where('id', $customerId)->whereNull('owner_user_id')->update(['owner_user_id' => $first]);

        return (int) DB::table('saas_customers')->where('id', $customerId)->value('owner_user_id');
    }

    public function is(?object $user): bool
    {
        return $user !== null && ($user->role ?? null) === 'customer_admin'
            && $this->id((int) $user->customer_id) === (int) $user->id;
    }

    /**
     * Whether the actor may create or change the target account. Only the owner touches another admin's account;
     * everybody manages their own one and every teacher and student.
     */
    public function mayManage(object $actor, object $target, ?bool $actorIsOwner = null): bool
    {
        return $target->role !== 'customer_admin' || (int) $target->id === (int) $actor->id
            || ($actorIsOwner ?? $this->is($actor));
    }

    /**
     * Hands the role over to another active admin of the tenant. The caller holds the tenant row lock.
     *
     * @return bool false when the target is not an active admin of this tenant other than the owner
     */
    public function transfer(Request $request, int $customerId, int $toUserId): bool
    {
        $fromId = $this->id($customerId);
        $target = DB::table('users')->where('customer_id', $customerId)->where('id', $toUserId)->lockForUpdate()->first();
        if (! $target || $target->role !== 'customer_admin' || $target->status !== 'active' || (int) $target->id === $fromId) {
            return false;
        }
        DB::table('saas_customers')->where('id', $customerId)->update(['owner_user_id' => $toUserId, 'updated_at' => now()]);
        AuditLog::record($request, $customerId, 'customer.owner_transferred', $toUserId,
            ['owner_user_id' => $fromId], ['owner_user_id' => $toUserId]);

        return true;
    }
}

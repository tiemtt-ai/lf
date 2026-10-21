<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sharing of a Media File with teachers of the same tenant (ADR-0021).
 *
 * A Media is private to its owner (`uploaded_by`) until shared. A share goes to one teacher (`user`) or to the whole
 * `teacher` role (a group: teachers added later see it too). Admins see every Media of the tenant and need no share.
 * A share that someone still uses cannot be revoked: the change is refused and says who uses the Media.
 */
class MediaShareService
{
    public const SCOPE_PRIVATE = 'private';

    public const SCOPE_TEACHERS = 'teachers';

    public const SCOPE_ALL_TEACHERS = 'all_teachers';

    public const SCOPES = [self::SCOPE_PRIVATE, self::SCOPE_TEACHERS, self::SCOPE_ALL_TEACHERS];

    private const ROLE_TEACHER = 'teacher';

    /**
     * The sharing state of some Media: [mediaId => ['scope' => ..., 'user_ids' => [...]]]. Media without a share is private.
     *
     * @param  iterable<int, int|string>  $mediaIds
     * @return array<int, array{scope: string, user_ids: array<int, int>, note: ?string}>
     */
    public function stateFor(int $customerId, iterable $mediaIds): array
    {
        $ids = collect($mediaIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $state = [];
        foreach ($ids as $id) {
            $state[$id] = ['scope' => self::SCOPE_PRIVATE, 'user_ids' => [], 'note' => null];
        }
        if ($ids === []) {
            return $state;
        }

        $rows = DB::table('media_file_shares')
            ->where('customer_id', $customerId)
            ->whereIn('media_file_id', $ids)
            ->whereNull('revoked_at')
            ->get(['media_file_id', 'grantee_type', 'grantee_user_id']);
        $notes = DB::table('media_files')->where('customer_id', $customerId)->whereIn('id', $ids)->pluck('share_note', 'id');

        foreach ($rows as $row) {
            $id = (int) $row->media_file_id;
            if ($row->grantee_type === 'role') {
                $state[$id]['scope'] = self::SCOPE_ALL_TEACHERS;
            } else {
                if ($state[$id]['scope'] !== self::SCOPE_ALL_TEACHERS) {
                    $state[$id]['scope'] = self::SCOPE_TEACHERS;
                }
                $state[$id]['user_ids'][] = (int) $row->grantee_user_id;
            }
        }

        foreach ($state as $id => $row) {
            // The note only means something while the Media is shared.
            $state[$id]['note'] = $row['scope'] === self::SCOPE_PRIVATE ? null : ($notes[$id] ?? null);
        }

        return $state;
    }

    /**
     * Media a teacher may see in a library or picker: their own plus every active share for them (directly or by role).
     */
    public function visibleToTeacher(int $customerId, int $teacherId): Builder
    {
        return DB::table('media_files')
            ->where('media_files.customer_id', $customerId)
            ->where('media_files.status', '!=', 'deleted')
            ->where(function (Builder $query) use ($customerId, $teacherId): void {
                $query->where('media_files.uploaded_by', $teacherId)
                    ->orWhereIn('media_files.id', DB::table('media_file_shares')->select('media_file_id')
                        ->where('customer_id', $customerId)->whereNull('revoked_at')
                        ->where(function (Builder $grant) use ($teacherId): void {
                            $grant->where('grantee_user_id', $teacherId)->orWhere('grantee_role', self::ROLE_TEACHER);
                        }));
            });
    }

    /**
     * Set who a Media is shared with. Only the owner or an admin of the tenant may do it.
     *
     * @param  array<int, int|string>  $teacherIds  used when the scope is `teachers`
     * @param  ?string  $note  what the owner tells the people it is shared with (kept only while shared)
     * @return array{scope: string, user_ids: array<int, int>, note: ?string}
     *
     * @throws MediaShareBlockedException when a teacher who would lose access still uses the Media
     */
    public function apply(User $actor, int $customerId, int $mediaId, string $scope, array $teacherIds = [], ?string $note = null): array
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw ValidationException::withMessages(['scope' => __('lf.LF_media_share_error_scope')]);
        }

        return DB::transaction(function () use ($actor, $customerId, $mediaId, $scope, $teacherIds, $note): array {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaId)
                ->where('status', '!=', 'deleted')->lockForUpdate()->first(['id', 'uploaded_by']);
            abort_if(! $media, 404);
            abort_unless(
                (int) $actor->customer_id === $customerId
                && ($actor->role === 'customer_admin' || (int) $media->uploaded_by === (int) $actor->id),
                403
            );

            $ownerId = (int) $media->uploaded_by;
            $tenantTeachers = DB::table('users')->where('customer_id', $customerId)->where('role', self::ROLE_TEACHER)
                ->where('status', 'active')->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $chosen = [];
            if ($scope === self::SCOPE_TEACHERS) {
                $chosen = collect($teacherIds)->map(fn ($id): int => (int) $id)->unique()->reject(fn (int $id): bool => $id === $ownerId)->values()->all();
                if ($chosen === [] || array_diff($chosen, $tenantTeachers) !== []) {
                    throw ValidationException::withMessages(['user_ids' => __('lf.LF_media_share_feedback_none')]);
                }
            }

            $desired = match ($scope) {
                self::SCOPE_ALL_TEACHERS => ['r:'.self::ROLE_TEACHER],
                self::SCOPE_TEACHERS => array_map(fn (int $id): string => 'u:'.$id, $chosen),
                default => [],
            };
            $active = DB::table('media_file_shares')->where('customer_id', $customerId)->where('media_file_id', $mediaId)
                ->whereNull('revoked_at')->get(['id', 'grantee_key', 'grantee_type', 'grantee_user_id']);
            $current = $active->pluck('grantee_key')->all();

            $before = $this->teachersWithAccess($active->pluck('grantee_key')->all(), $tenantTeachers);
            $after = $this->teachersWithAccess($desired, $tenantTeachers);
            $lost = array_values(array_diff($before, $after));
            $this->refuseWhenStillUsed($customerId, $mediaId, $ownerId, $lost);

            $now = now();
            foreach ($active->filter(fn ($row): bool => ! in_array($row->grantee_key, $desired, true)) as $row) {
                DB::table('media_file_shares')->where('customer_id', $customerId)->where('id', $row->id)
                    ->update(['revoked_at' => $now, 'revoked_by' => $actor->id, 'updated_at' => $now]);
            }
            foreach (array_diff($desired, $current) as $key) {
                $isRole = str_starts_with($key, 'r:');
                DB::table('media_file_shares')->insert([
                    'customer_id' => $customerId,
                    'media_file_id' => $mediaId,
                    'grantee_type' => $isRole ? 'role' : 'user',
                    'grantee_user_id' => $isRole ? null : (int) substr($key, 2),
                    'grantee_role' => $isRole ? self::ROLE_TEACHER : null,
                    'grantee_key' => $key,
                    'shared_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $note = $scope === self::SCOPE_PRIVATE ? null : (trim((string) $note) === '' ? null : mb_substr(trim((string) $note), 0, 300));
            DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaId)->update(['share_note' => $note, 'updated_at' => $now]);

            return $this->stateFor($customerId, [$mediaId])[$mediaId];
        });
    }

    /**
     * Teachers who can see a Media for these active grantee keys.
     *
     * @param  array<int, string>  $keys
     * @param  array<int, int>  $tenantTeachers
     * @return array<int, int>
     */
    private function teachersWithAccess(array $keys, array $tenantTeachers): array
    {
        if (in_array('r:'.self::ROLE_TEACHER, $keys, true)) {
            return $tenantTeachers;
        }

        return collect($keys)->filter(fn (string $key): bool => str_starts_with($key, 'u:'))
            ->map(fn (string $key): int => (int) substr($key, 2))->values()->all();
    }

    /**
     * @param  array<int, int>  $teachersLosingAccess
     */
    private function refuseWhenStillUsed(int $customerId, int $mediaId, int $ownerId, array $teachersLosingAccess): void
    {
        if ($teachersLosingAccess === []) {
            return;
        }

        $users = DB::table('media_file_usages')
            ->join('users', function ($join) use ($customerId): void {
                $join->on('users.id', '=', 'media_file_usages.created_by')->where('users.customer_id', '=', $customerId);
            })
            ->where('media_file_usages.customer_id', $customerId)
            ->where('media_file_usages.media_file_id', $mediaId)
            ->where('media_file_usages.status', 'active')
            ->whereIn('media_file_usages.created_by', $teachersLosingAccess)
            ->where('media_file_usages.created_by', '!=', $ownerId)
            ->groupBy('users.id', 'users.name')
            ->get(['users.id', 'users.name', DB::raw('COUNT(*) as places')]);

        if ($users->isNotEmpty()) {
            throw new MediaShareBlockedException($this->blockedUsers($users));
        }
    }

    /**
     * @param  Collection<int, object>  $users
     * @return array<int, array{id: int, name: string, places: int}>
     */
    private function blockedUsers(Collection $users): array
    {
        return $users->map(fn (object $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name, 'places' => (int) $user->places])->all();
    }
}

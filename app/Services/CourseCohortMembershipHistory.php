<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The history of a class membership and the one test every reader shares: is a Session inside the time the learner
 * belonged to the class (ADR-0034). A Session outside it takes no attendance, no make-up and no manual result, and
 * does not count toward the learner's attendance rate.
 *
 * `joined_at`, `left_at` and the history times are wall-clock in the application timezone, as they are stored.
 */
final class CourseCohortMembershipHistory
{
    public const TABLE = 'core_course_cohort_student_changes';

    public const BEFORE_JOIN = 'before_join';

    public const NOT_MEMBER = 'not_member';

    /**
     * Adds one history row; call it in the transaction that changes the membership.
     *
     * @param  object  $membership  the row before the change, with id, customer_id, cohort_id and enrollment_id
     * @param  array{old_joined_at?: mixed, new_joined_at?: mixed, absent_from?: mixed, absent_to?: mixed, cohort_id?: int}  $values
     */
    public static function record(object $membership, string $type, ?int $actorId, array $values = []): void
    {
        $time = fn (mixed $value): ?string => $value === null ? null : CarbonImmutable::parse((string) $value)->format('Y-m-d H:i:s');
        DB::table(self::TABLE)->insert([
            'customer_id' => (int) $membership->customer_id,
            'cohort_student_id' => (int) $membership->id,
            'cohort_id' => (int) ($values['cohort_id'] ?? $membership->cohort_id),
            'enrollment_id' => (int) $membership->enrollment_id,
            'change_type' => $type,
            'old_joined_at' => $time($values['old_joined_at'] ?? null),
            'new_joined_at' => $time($values['new_joined_at'] ?? null),
            'absent_from' => $time($values['absent_from'] ?? null),
            'absent_to' => $time($values['absent_to'] ?? null),
            'changed_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    /**
     * The times each Enrollment did not belong to the class, between leaving and being added back.
     *
     * @param  array<int, int>  $enrollmentIds
     * @return Collection<int, Collection<int, object>> keyed by Enrollment; each gap has `from` and `to` (UTC)
     */
    public static function gaps(int $customerId, int $cohortId, array $enrollmentIds): Collection
    {
        if ($enrollmentIds === []) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->where('customer_id', $customerId)->where('cohort_id', $cohortId)->whereIn('enrollment_id', $enrollmentIds)
            ->where('change_type', 'rejoined')
            ->get(['enrollment_id', 'absent_from', 'absent_to'])
            ->map(fn (object $row): object => (object) [
                'enrollment_id' => (int) $row->enrollment_id,
                'from' => self::utc($row->absent_from),
                'to' => self::utc($row->absent_to),
            ])
            ->groupBy('enrollment_id');
    }

    /**
     * Why a Session that ends at `$sessionEnd` is outside the learner's time in the class: `before_join`,
     * `not_member` (after leaving, or between leaving and being added back), or null when it is inside.
     *
     * @param  mixed  $joinedAt  the membership's `joined_at`
     * @param  mixed  $leftAt  its `left_at`, null while the learner belongs to the class
     * @param  Collection<int, object>|null  $gaps  the learner's gaps from {@see gaps()}
     */
    public static function outside(mixed $joinedAt, mixed $leftAt, ?Collection $gaps, CarbonInterface $sessionEnd): ?string
    {
        $end = CarbonImmutable::instance($sessionEnd)->utc();
        if ($joinedAt !== null && $end->lt(self::utc($joinedAt))) {
            return self::BEFORE_JOIN;
        }
        if ($leftAt !== null && $end->gt(self::utc($leftAt))) {
            return self::NOT_MEMBER;
        }
        foreach ($gaps ?? [] as $gap) {
            if ($end->gt($gap->from) && $end->lte($gap->to)) {
                return self::NOT_MEMBER;
            }
        }

        return null;
    }

    /**
     * The history of the given memberships, newest first, with who made each change.
     *
     * @param  array<int, int>  $membershipIds
     * @return Collection<int, Collection<int, object>> keyed by membership id
     */
    public static function forMemberships(int $customerId, array $membershipIds): Collection
    {
        if ($membershipIds === []) {
            return collect();
        }

        return DB::table(self::TABLE.' as changes')
            ->leftJoin('users as actors', function ($join) use ($customerId): void {
                $join->on('actors.id', '=', 'changes.changed_by')->where('actors.customer_id', $customerId);
            })
            ->where('changes.customer_id', $customerId)->whereIn('changes.cohort_student_id', $membershipIds)
            ->orderByDesc('changes.created_at')->orderByDesc('changes.id')
            ->get(['changes.cohort_student_id', 'changes.change_type', 'changes.old_joined_at', 'changes.new_joined_at',
                'changes.absent_from', 'changes.absent_to', 'changes.created_at', 'actors.name as actor_name'])
            ->groupBy('cohort_student_id');
    }

    /** A stored time as the pages show it; a dash when there is none. */
    public static function at(mixed $value): string
    {
        return $value === null ? '—' : CarbonImmutable::parse((string) $value)->format('d/m/Y H:i');
    }

    /** One history row in words. */
    public static function label(object $change): string
    {
        return match ($change->change_type) {
            'rejoined' => __('lf.LF_course_cohort_student_history_rejoined', ['from' => self::at($change->absent_from), 'to' => self::at($change->absent_to)]),
            'left' => __('lf.LF_course_cohort_student_history_left'),
            'transferred' => __('lf.LF_course_cohort_student_history_transferred', ['to' => self::at($change->new_joined_at)]),
            default => __('lf.LF_course_cohort_student_history_joined_at_changed', ['from' => self::at($change->old_joined_at), 'to' => self::at($change->new_joined_at)]),
        };
    }

    private static function utc(mixed $wallClock): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $wallClock, config('app.timezone'))->utc();
    }
}

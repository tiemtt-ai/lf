<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ghi danh of the Kiểm tra module (core_assessment_quiz_registrations, ADR-0025 D7–D8): one student's right to take one
 * quiz. This side is the admin / teacher one: granting a published quiz to students and cancelling. Learners register
 * themselves on the tenant website (built with the learner side).
 *
 * A student has at most one active registration per quiz; a registration is never deleted, only cancelled. Teachers
 * grant and see only the registrations of quizzes they created; admins the whole tenant.
 */
final class AssessmentRegistrationService
{
    public const TABLE = 'core_assessment_quiz_registrations';

    public const STATUSES = ['active', 'exhausted', 'cancelled'];

    public const SOURCES = ['self_registered', 'granted'];

    public function __construct(private readonly AssessmentQuizService $quizzes) {}

    /** Registrations this user may see, joined to their quiz as `z`. */
    public function scoped(int $customerId, User $user, string $alias = 'r'): Builder
    {
        return DB::table(self::TABLE.' as '.$alias)
            ->join(AssessmentQuizService::TABLE.' as z', function ($join) use ($alias): void {
                $join->on('z.id', '=', $alias.'.quiz_id')->on('z.customer_id', '=', $alias.'.customer_id');
            })
            ->where($alias.'.customer_id', $customerId)
            ->when($user->role !== 'customer_admin', fn ($query) => $query->where('z.created_by', $user->id));
    }

    /**
     * Grants a published quiz to students. Students who already hold an active registration for it are skipped.
     *
     * @param  list<int>  $studentIds
     * @return array{granted: int, skipped: int}
     */
    public function grant(int $customerId, User $user, int $quizId, array $studentIds, ?string $note): array
    {
        return DB::transaction(function () use ($customerId, $user, $quizId, $studentIds, $note): array {
            $quiz = $this->quizzes->find($customerId, $user, $quizId, true);
            if ($quiz->status !== 'published' || $quiz->current_version_id === null) {
                throw ValidationException::withMessages(['quiz_id' => __('lf.LF_assessment_registration_error_quiz_not_published')]);
            }

            $students = $this->students($customerId)->whereIn('id', $studentIds)->pluck('id')->map(fn ($id) => (int) $id);
            if ($students->count() !== count($studentIds)) {
                throw ValidationException::withMessages(['student_ids' => __('lf.LF_assessment_registration_error_student')]);
            }

            $holding = DB::table(self::TABLE)->where('customer_id', $customerId)->where('quiz_id', $quizId)
                ->where('status', 'active')->whereIn('student_id', $studentIds)->lockForUpdate()
                ->pluck('student_id')->map(fn ($id) => (int) $id)->flip();
            $new = $students->reject(fn (int $id) => $holding->has($id))->values();

            $now = now();
            if ($new->isNotEmpty()) {
                DB::table(self::TABLE)->insert($new->map(fn (int $studentId) => [
                    'customer_id' => $customerId,
                    'quiz_id' => $quizId,
                    'student_id' => $studentId,
                    'source' => 'granted',
                    'granted_by' => $user->id,
                    'status' => 'active',
                    'attempts_used' => 0,
                    'registered_at' => $now,
                    'note' => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }

            return ['granted' => $new->count(), 'skipped' => $students->count() - $new->count()];
        });
    }

    /**
     * All or nothing. Cancels active registrations; attempts already made stay as they are.
     *
     * @param  list<int>  $ids
     * @return array{name: string}|null the first registration that is not active, or null when cancelled
     */
    public function cancel(int $customerId, User $user, array $ids): ?array
    {
        return DB::transaction(function () use ($customerId, $user, $ids): ?array {
            $rows = $this->scoped($customerId, $user)->whereIn('r.id', $ids)->orderBy('r.id')->lockForUpdate()
                ->leftJoin('users as s', 's.id', '=', 'r.student_id')
                ->get(['r.id', 'r.status', 's.name as student_name', 'z.title as quiz_title']);
            abort_if($rows->count() !== count($ids), 404);

            $inactive = $rows->first(fn (object $row) => $row->status !== 'active');
            if ($inactive !== null) {
                return ['name' => $inactive->student_name.' · '.$inactive->quiz_title];
            }

            $now = now();
            DB::table(self::TABLE)->where('customer_id', $customerId)->whereIn('id', $ids)->update([
                'status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => $user->id, 'updated_at' => $now,
            ]);

            return null;
        });
    }

    /** Active students of the tenant: who can be granted a quiz. */
    public function students(int $customerId): Builder
    {
        return DB::table('users')->where('customer_id', $customerId)->where('role', 'student')->where('status', 'active');
    }

    /** Published quizzes this user may grant, by title. */
    public function grantableQuizzes(int $customerId, User $user): Collection
    {
        return $this->quizzes->scoped($customerId, $user)->where('status', 'published')->whereNotNull('current_version_id')
            ->orderBy('title')->get(['id', 'title']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\LiveClassCohortSessionReadModel;
use App\Services\LiveClassSessionPolicy;
use App\Support\TenantContext;
use App\Support\XlsxDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excel downloads of one Cohort: its roster (Học viên tab) and its attendance as a student × session grid
 * (Điểm danh tab). Read-only; every query is pinned to the current tenant and the Cohort is found inside it.
 */
class CourseCohortExportController extends Controller
{
    private const ATTENDANCE_STATUSES = ['registered', 'present', 'late', 'absent', 'excused'];

    public function __construct(
        private readonly LiveClassSessionPolicy $sessionPolicy,
        private readonly LiveClassCohortSessionReadModel $sessionReadModel,
    ) {}

    /** Active students of the Cohort, as the Học viên tab lists them (keyword filter included). */
    public function students(Request $request, int $id): Response
    {
        $customerId = $this->customerId($request);
        $cohort = $this->findCohort($customerId, $id);
        $keyword = trim((string) $request->query('student_keyword', ''));

        $students = $this->activeRoster($customerId, $id)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('students.name', 'like', '%'.$keyword.'%')
                        ->orWhere('students.email', 'like', '%'.$keyword.'%')
                        ->orWhere('enrollments.id', preg_replace('/\D+/', '', $keyword) ?: 0);
                });
            })
            ->orderByDesc('memberships.joined_at')
            ->orderByDesc('memberships.id')
            ->limit(XlsxDownload::MAX_ROWS)
            ->get([
                'students.name as student_name', 'students.email as student_email', 'students.phone as student_phone',
                'enrollments.id as enrollment_id', 'enrollments.status as enrollment_status',
                'enrollments.access_starts_at', 'enrollments.access_ends_at', 'memberships.joined_at',
            ]);

        return XlsxDownload::response(
            [
                __('lf.table_no'), __('lf.LF_course_cohort_student_common_student'), __('lf.LF_common_label_email'),
                __('lf.LF_common_label_phone'), __('lf.LF_export_col_enrollment_id'), __('lf.LF_export_col_enrollment_status'),
                __('lf.LF_course_cohort_student_common_joined_at'), __('lf.LF_course_enrollment_common_access_starts_at'),
                __('lf.LF_course_enrollment_common_access_ends_at'),
            ],
            $students->values()->map(fn (object $row, int $index) => [
                $index + 1, $row->student_name, $row->student_email, $row->student_phone, $row->enrollment_id,
                __('lf.LF_course_enrollment_common_'.$row->enrollment_status), XlsxDownload::dateTime($row->joined_at),
                XlsxDownload::dateTime($row->access_starts_at), XlsxDownload::dateTime($row->access_ends_at),
            ]),
            __('lf.LF_export_file_cohort_students').'-'.($cohort->code ?: $cohort->id),
            $cohort->name,
        );
    }

    /**
     * One row per active student, one column per Session the Điểm danh tab offers; a Session not marked yet reads
     * as the tab shows it (Dự kiến). Totals and the attendance rate (present + late over those Sessions) close the row.
     */
    public function attendance(Request $request, int $id): Response
    {
        abort_unless((bool) config('liveclass.attendance_enabled'), 404);

        $customerId = $this->customerId($request);
        $cohort = $this->findCohort($customerId, $id);
        $sessions = $this->sessionReadModel->forCohort($customerId, $id)
            ->filter(fn (object $session): bool => $this->sessionPolicy->canRecordAttendance($session, now()))
            ->values();

        $students = $this->activeRoster($customerId, $id)
            ->orderBy('students.name')
            ->orderBy('memberships.id')
            ->limit(XlsxDownload::MAX_ROWS)
            ->get(['enrollments.id as enrollment_id', 'students.name as student_name', 'students.email as student_email']);

        $marks = $sessions->isEmpty() || $students->isEmpty() ? collect() : DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)
            ->whereIn('session_id', $sessions->pluck('id'))
            ->whereIn('enrollment_id', $students->pluck('enrollment_id'))
            ->get(['session_id', 'enrollment_id', 'status'])
            ->keyBy(fn (object $mark) => $mark->session_id.':'.$mark->enrollment_id);

        $header = [__('lf.table_no'), __('lf.LF_course_cohort_student_common_student'), __('lf.LF_common_label_email')];
        foreach ($sessions as $session) {
            $header[] = '#'.$session->session_no.' · '.XlsxDownload::date($session->scheduled_start_at);
        }
        foreach (['present', 'late', 'absent', 'excused'] as $status) {
            $header[] = __('lf.LF_course_cohort_attendance_status_'.$status);
        }
        $header[] = __('lf.LF_export_col_attendance_rate');

        $rows = $students->values()->map(function (object $student, int $index) use ($sessions, $marks): array {
            $row = [$index + 1, $student->student_name, $student->student_email];
            $counts = array_fill_keys(self::ATTENDANCE_STATUSES, 0);
            foreach ($sessions as $session) {
                $status = $marks->get($session->id.':'.$student->enrollment_id)?->status;
                $status = in_array($status, self::ATTENDANCE_STATUSES, true) ? $status : 'registered';
                $counts[$status]++;
                $row[] = __('lf.LF_course_cohort_attendance_status_'.$status);
            }
            array_push($row, $counts['present'], $counts['late'], $counts['absent'], $counts['excused']);
            $row[] = $sessions->isEmpty()
                ? ''
                : XlsxDownload::number(($counts['present'] + $counts['late']) * 100 / $sessions->count()).'%';

            return $row;
        });

        return XlsxDownload::response(
            $header,
            $rows,
            __('lf.LF_export_file_cohort_attendance').'-'.($cohort->code ?: $cohort->id),
            $cohort->name,
        );
    }

    private function activeRoster(int $customerId, int $cohortId)
    {
        return DB::table('core_course_cohort_students as memberships')
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'memberships.student_id')
                    ->where('students.customer_id', '=', $customerId);
            })
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'memberships.enrollment_id')
                    ->where('enrollments.customer_id', '=', $customerId);
            })
            ->where('memberships.customer_id', $customerId)
            ->where('memberships.cohort_id', $cohortId)
            ->where('memberships.status', 'active');
    }

    private function findCohort(int $customerId, int $id): object
    {
        $cohort = DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first(['id', 'name', 'code']);
        abort_if(! $cohort, 404);

        return $cohort;
    }

    private function customerId(Request $request): int
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return $customerId;
    }
}

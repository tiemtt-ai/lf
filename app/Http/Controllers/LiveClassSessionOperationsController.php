<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Services\LiveClassSessionOperations;
use App\Services\LiveClassWhiteboardService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ADR-0002 § Teacher Session Operations Amendment: a Session's own teachers change its meeting link, room, address
 * and attendance tool, and the administrator changes the meeting link of a Session that has already started.
 */
final class LiveClassSessionOperationsController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    public function __construct(
        private readonly LiveClassWhiteboardService $whiteboards,
        private readonly LiveClassSessionOperations $operations,
    ) {}

    public function teacherUpdate(Request $request, int $session): RedirectResponse
    {
        $customerId = (int) TenantContext::customerId();
        abort_if(! $customerId, 404);
        // Only a teacher assigned to this very Session, inside their assignment window, reaches it.
        $row = $this->whiteboards->teacherSessionRow($customerId, (int) $request->user()->id, $session);

        return $this->apply($request, $customerId, $row, redirect()->route('teacher.live-sessions.index'), 'sessionOperations');
    }

    /** The administrator's general edit closes at the planned start; the meeting link alone stays open until the end. */
    public function adminMeetingLink(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeCohortManager($request, $cohort);
        $row = DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->where('id', $session)->first() ?? abort(404);
        abort_unless(DB::table('core_course_cohorts')->where('customer_id', $customerId)->where('id', $cohort)
            ->where('status', 'active')->exists(), 422, __('lf.LF_course_cohort_runtime_requires_active'));
        $request->replace($request->only(['meeting_url']));

        return $this->apply($request, $customerId, $row,
            redirect()->route($this->cohortArea().'.show', ['id' => $cohort, 'tab' => 'sessions']), 'sessionMeetingLink');
    }

    private function apply(Request $request, int $customerId, object $session, RedirectResponse $back, string $bag): RedirectResponse
    {
        $editable = $this->operations->editable($customerId, $session);
        abort_unless($editable['link'] || $editable['place'], 422, __('lf.LF_liveclass_session_operations_locked'));

        $validated = $request->validateWithBag($bag, [
            'meeting_url' => ['nullable', 'string', 'max:2000', 'url:http,https'],
            'room_name' => [$editable['place'] ? 'required' : 'nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'attendance_tool' => ['nullable', Rule::in(LiveClassSessionOperations::ATTENDANCE_TOOLS)],
        ], [], [
            'meeting_url' => __('lf.LF_course_cohort_session_meeting_url'),
            'room_name' => __('lf.LF_course_cohort_session_room'),
            'address' => __('lf.LF_course_cohort_session_address'),
        ]);
        $changed = $this->operations->update($request, $customerId, $session, $validated);

        return $back->with('success', $changed === []
            ? __('lf.LF_liveclass_session_operations_unchanged')
            : __('lf.LF_liveclass_session_operations_saved'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\LiveClassAttendanceCheckIn;
use App\Services\LiveClassAttendanceDevice;
use App\Services\LiveClassAttendanceRecorder;
use App\Services\LiveClassSelfStudySession;
use App\Services\LiveClassWhiteboardService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The learner's own ways into a Session that write Attendance (ADR-0002 § Attendance Policy And Recording Amendment,
 * part B): "Vào phòng họp" and the "Điểm danh" button. The entry time is when the server received the request.
 */
final class LiveClassAttendanceEntryController extends Controller
{
    public function __construct(
        private readonly LiveClassWhiteboardService $whiteboards,
        private readonly LiveClassAttendanceRecorder $recorder,
        private readonly LiveClassAttendanceDevice $devices,
    ) {}

    /** Records the entry, then sends the learner to the meeting. The meeting opens whatever the recorder answers. */
    public function meeting(Request $request, int $session): RedirectResponse
    {
        $customerId = (int) TenantContext::customerId();
        [$row, $membership, $refusal] = $this->whiteboards->learnerEntry($customerId, (int) $request->user()->id, $session);
        if ($refusal !== null) {
            return $this->refused($refusal);
        }
        $url = LiveClassWhiteboardService::meetingUrl($row);
        abort_if($url === null, 404);

        if (LiveClassAttendanceCheckIn::enabled()) {
            $this->recorder->enter($row, $membership, 'meeting', CarbonImmutable::now());
        }

        return redirect()->away($url);
    }

    /** The "Điểm danh" button of an in-room Session whose attendance tool is the button. */
    public function attend(Request $request, int $session): RedirectResponse
    {
        abort_unless(LiveClassAttendanceCheckIn::enabled(), 404);
        $customerId = (int) TenantContext::customerId();
        [$row, $membership, $refusal] = $this->whiteboards->learnerEntry($customerId, (int) $request->user()->id, $session);
        if ($refusal !== null) {
            return $this->refused($refusal);
        }

        // One device records one learner in a Session in a room (MK 2.4); a self-study Session is learned at home.
        $outcome = $this->devices->guard($request, $customerId, $session, (int) $request->user()->id, ! LiveClassSelfStudySession::is($row),
            fn (): array => $this->recorder->enter($row, $membership, 'button', CarbonImmutable::now()));

        return back()->with('lf_attendance_entry', ['session' => $session] + $outcome);
    }

    /**
     * Nothing is recorded; the learner is told why instead of meeting a bare 403. An Enrollment that is no longer
     * active may not even show its class any more, so they go to "Lớp học của tôi"; a closed Session goes back.
     */
    private function refused(string $refusal): RedirectResponse
    {
        $notice = __('lf.LF_liveclass_attendance_entry_'.$refusal);

        return $refusal === 'inactive'
            ? redirect()->route('student.courses.index', ['tab' => 'classes'])->with('lf_learn_notice', $notice)
            : back()->with('lf_learn_notice', $notice);
    }
}

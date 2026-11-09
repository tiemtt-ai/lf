<?php

namespace App\Http\Controllers;

use App\Services\LiveClassAttendanceCheckIn;
use App\Services\LiveClassWhiteboardService;
use App\Support\LiveClassBoardContext;
use App\Support\LoginExperience;
use App\Support\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * QR check-in (ADR-0002 § Attendance Check-In Amendment): the presenter's
 * board fetches the rotating code; a learner's phone opens the link in it.
 *
 * The link is opened first without sign-in: a valid code is remembered in the
 * HTTP session with the time it arrived, the code is dropped from the URL,
 * and the learner signs in if needed before confirming.
 */
final class LiveClassAttendanceCheckInController extends Controller
{
    /** HTTP-session key of a received, not yet recorded check-in. */
    public const PENDING = 'lf_attendance_checkin';

    /** HTTP-session key the student sign-in returns to. */
    public const RETURN_TO = 'lf_attendance_checkin_return';

    private const PENDING_SECONDS = 600;

    public function __construct(
        private readonly LiveClassWhiteboardService $whiteboards,
        private readonly LiveClassAttendanceCheckIn $checkIn,
    ) {}

    public function teacherCode(Request $request, int $session): JsonResponse
    {
        return $this->code($this->whiteboards->teacherContext($this->customerId(), (int) $request->user()->id, $session));
    }

    public function adminCode(Request $request, int $cohort, int $session): JsonResponse
    {
        return $this->code($this->whiteboards->adminContext($this->customerId(), (int) $request->user()->id, $cohort, $session));
    }

    /**
     * The short link inside the QR code. A valid code is remembered with the
     * time it arrived, then the learner lands on the check-in page.
     */
    public function scan(Request $request, int $session, string $code): RedirectResponse
    {
        abort_unless(LiveClassAttendanceCheckIn::enabled(), 404);
        $customerId = $this->customerId();
        $page = route('student.live-sessions.check-in', $session);

        if (! $this->checkIn->accepts($customerId, $session, $code)) {
            return redirect()->to($page)->with('lf_checkin_result', 'expired');
        }
        $request->session()->put(self::PENDING, [
            'customer_id' => $customerId, 'session_id' => $session, 'at' => CarbonImmutable::now()->getTimestamp(),
        ]);

        return redirect()->to($page);
    }

    /** The check-in page; it never writes Attendance itself. */
    public function show(Request $request, int $session): View|RedirectResponse
    {
        abort_unless(LiveClassAttendanceCheckIn::enabled(), 404);
        $customerId = $this->customerId();
        $page = route('student.live-sessions.check-in', $session);

        $user = $request->user();
        $pending = $this->pending($request, $customerId, $session);
        if (! $user && $pending) {
            $request->session()->put(self::RETURN_TO, $page);

            return redirect()->to(LoginExperience::studentUrl());
        }

        return view('liveclass-attendance.check-in', [
            'tenant' => TenantContext::customer(),
            'studentMode' => $user?->role === 'student',
            'session' => $session,
            'pending' => $user?->role === 'student' && (int) $user->customer_id === $customerId && $pending !== null,
            'wrongAccount' => $user !== null && ($user->role !== 'student' || (int) $user->customer_id !== $customerId),
            'result' => session('lf_checkin_result'),
            'recorded' => session('lf_checkin_recorded'),
        ]);
    }

    public function store(Request $request, int $session): RedirectResponse
    {
        abort_unless(LiveClassAttendanceCheckIn::enabled(), 404);
        $customerId = $this->customerId();
        $page = route('student.live-sessions.check-in', $session);
        $pending = $this->pending($request, $customerId, $session);
        $request->session()->forget(self::PENDING);
        if (! $pending) {
            return redirect()->to($page)->with('lf_checkin_result', 'expired');
        }

        try {
            $context = $this->whiteboards->learnerContext($customerId, (int) $request->user()->id, $session);
        } catch (HttpExceptionInterface) {
            // Not a member, not active or the Session is not live: nothing about it is disclosed.
            return redirect()->to($page)->with('lf_checkin_result', 'not_allowed');
        }

        $outcome = $this->checkIn->record($context, CarbonImmutable::createFromTimestamp($pending['at']));

        return redirect()->to($page)->with('lf_checkin_result', $outcome['result'])->with('lf_checkin_recorded', [
            'title' => $context->sessionTitle,
            'cohort' => $context->cohortName,
            'status' => $outcome['status'] ?? null,
            'joined_at' => $outcome['joined_at'] ?? null,
        ]);
    }

    private function code(LiveClassBoardContext $context): JsonResponse
    {
        $issued = $this->checkIn->issue($context);
        $url = route('student.live-sessions.check-in.scan', ['session' => $context->sessionId, 'code' => $issued['code']]);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 2), new SvgImageBackEnd)))->writeString($url);

        return response()->json([
            'url' => $url,
            'svg' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'refresh_in' => $issued['refresh_in'],
        ] + $this->checkIn->counts($context))->header('Cache-Control', 'no-store');
    }

    /** @return array{customer_id: int, session_id: int, at: int}|null */
    private function pending(Request $request, int $customerId, int $session): ?array
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || (int) ($pending['customer_id'] ?? 0) !== $customerId
            || (int) ($pending['session_id'] ?? 0) !== $session
            || CarbonImmutable::now()->getTimestamp() - (int) ($pending['at'] ?? 0) > self::PENDING_SECONDS) {
            return null;
        }

        return ['customer_id' => $customerId, 'session_id' => $session, 'at' => (int) $pending['at']];
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return (int) $customerId;
    }
}

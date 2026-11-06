<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentQuizService as Quizzes;
use App\Services\Assessment\AssessmentRegistrationService as Registrations;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ghi danh of the Kiểm tra module, admin and teacher side: the list of registrations, granting a published quiz to
 * students and cancelling. Admins see the tenant, teachers the registrations of their own quizzes.
 */
final class AssessmentRegistrationController extends Controller
{
    public function __construct(
        private readonly Registrations $registrations,
        private readonly Quizzes $quizzes,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'quiz_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);
        $filters = [
            'keyword' => trim($validated['keyword'] ?? ''),
            'quiz_id' => isset($validated['quiz_id']) ? (int) $validated['quiz_id'] : null,
            'source' => in_array($validated['source'] ?? null, Registrations::SOURCES, true) ? $validated['source'] : null,
            'status' => in_array($validated['status'] ?? null, Registrations::STATUSES, true) ? $validated['status'] : null,
        ];

        $registrations = $this->registrations->scoped($customerId, $user)
            ->leftJoin('users as s', 's.id', '=', 'r.student_id')
            ->leftJoin('users as g', 'g.id', '=', 'r.granted_by')
            ->leftJoin(Quizzes::VERSIONS_TABLE.' as v', function ($join): void {
                $join->on('v.id', '=', 'z.current_version_id')->on('v.customer_id', '=', 'z.customer_id');
            })
            ->when($filters['keyword'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('s.name', 'like', '%'.$filters['keyword'].'%')
                ->orWhere('s.email', 'like', '%'.$filters['keyword'].'%')))
            ->when($filters['quiz_id'], fn ($query, $id) => $query->where('r.quiz_id', $id))
            ->when($filters['source'], fn ($query, $source) => $query->where('r.source', $source))
            ->when($filters['status'], fn ($query, $status) => $query->where('r.status', $status))
            ->select('r.*', 's.name as student_name', 's.email as student_email', 'g.name as granter_name',
                'z.title as quiz_title', 'v.max_attempts')
            ->orderByDesc('r.registered_at')
            ->orderByDesc('r.id')
            ->paginate(10)
            ->withQueryString();

        return view('assessments.registrations.index', [
            'registrations' => $registrations,
            'filters' => $filters,
            'quizzes' => $this->quizzes->scoped($customerId, $user)->whereNotNull('current_version_id')->orderBy('title')->get(['id', 'title']),
            'routePrefix' => $this->routePrefix($request),
            'quizRoute' => str_replace('registrations', 'quizzes', $this->routePrefix($request)),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->ajax(), 404);
        $customerId = $this->customerId();

        return view('assessments.registrations.dialog', [
            'quizzes' => $this->registrations->grantableQuizzes($customerId, $request->user()),
            'quizId' => (int) $request->query('quiz_id'),
            'students' => $this->registrations->students($customerId)->orderBy('name')->limit(2000)->get(['id', 'name', 'email']),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'quiz_id' => ['required', 'integer'],
            'student_ids' => ['required', 'array', 'min:1', 'max:500'],
            'student_ids.*' => ['integer', 'distinct'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $note = trim((string) ($validated['note'] ?? ''));
        $result = $this->registrations->grant($this->customerId(), $request->user(), (int) $validated['quiz_id'],
            array_map('intval', $validated['student_ids']), $note === '' ? null : $note);

        $message = trans_choice('lf.LF_assessment_registration_granted', $result['granted'], ['count' => $result['granted']]);
        if ($result['skipped'] > 0) {
            $message .= ' '.trans_choice('lf.LF_assessment_registration_skipped', $result['skipped'], ['count' => $result['skipped']]);
        }

        return $request->expectsJson()
            ? response()->json(['message' => $message], 201)
            : back()->with('success', $message);
    }

    public function bulkCancel(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['ids']);
        $blocked = $this->registrations->cancel($this->customerId(), $request->user(), $ids);

        if ($blocked !== null) {
            $message = '«'.$blocked['name'].'» — '.__('lf.LF_assessment_registration_cancel_blocked');

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : back()->withErrors(['registration' => $message]);
        }

        $message = trans_choice('lf.LF_assessment_registration_cancelled', count($ids), ['count' => count($ids)]);

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->with('success', $message);
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.assessments.registrations',
            'teacher' => 'teacher.assessments.registrations',
            default => abort(403),
        };
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return $customerId;
    }
}

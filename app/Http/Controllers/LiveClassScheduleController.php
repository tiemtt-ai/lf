<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Http\Requests\LiveClassScheduleRequest;
use App\Services\LiveClassSchedulePolicy;
use App\Services\LiveClassSchedulePreviewService;
use App\Services\LiveClassScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiveClassScheduleController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    private string $cohortRoutePrefix = 'admin.course-cohorts';

    public function __construct(
        private readonly LiveClassSchedulePolicy $policy,
        private readonly LiveClassSchedulePreviewService $previewService,
        private readonly LiveClassScheduleService $scheduleService
    ) {}

    public function create(Request $request, int $cohort): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->cohort($customerId, $cohort);
        abort_unless($this->policy->canMutate($cohortRow), 403);

        return redirect()->route('admin.course-cohorts.show', [
            'id' => $cohort,
            'tab' => 'schedules',
            'schedule_form' => 'create',
        ])->withFragment('cohort-schedule-editor');
    }

    public function store(LiveClassScheduleRequest $request, int $cohort): RedirectResponse
    {
        $scheduleId = $this->scheduleService->create(
            $this->customerId(),
            $cohort,
            (int) $request->user()->id,
            $request->validated()
        );

        return redirect()->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'schedules'])
            ->with('success', __('lf.LF_course_cohort_schedule_created'));
    }

    public function show(Request $request, int $cohort, int $schedule): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $this->cohort($customerId, $cohort);
        $this->schedule($customerId, $cohort, $schedule);

        return redirect()->route('admin.course-cohorts.show', [
            'id' => $cohort,
            'tab' => 'schedules',
            'schedule_form' => 'view',
            'schedule_id' => $schedule,
        ])->withFragment('cohort-schedule-editor');
    }

    public function edit(Request $request, int $cohort, int $schedule): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->cohort($customerId, $cohort);
        abort_unless($this->policy->canMutate($cohortRow), 403);
        $this->schedule($customerId, $cohort, $schedule);

        return redirect()->route('admin.course-cohorts.show', [
            'id' => $cohort,
            'tab' => 'schedules',
            'schedule_form' => 'edit',
            'schedule_id' => $schedule,
        ])->withFragment('cohort-schedule-editor');
    }

    public function update(
        LiveClassScheduleRequest $request,
        int $cohort,
        int $schedule
    ): RedirectResponse {
        $this->scheduleService->update(
            $this->customerId(),
            $cohort,
            $schedule,
            (int) $request->user()->id,
            $request->validated()
        );

        return redirect()->route('admin.course-cohorts.show', ['id' => $cohort, 'tab' => 'schedules'])
            ->with('success', __('lf.LF_course_cohort_schedule_updated'));
    }

    public function preview(LiveClassScheduleRequest $request, int $cohort): JsonResponse
    {
        $validated = $request->validated();
        $preview = $this->previewService->calculate(
            $validated['starts_on'],
            $validated['ends_on'],
            $validated['timezone'],
            $validated['slots'],
            $validated['exclusions'] ?? []
        );

        return response()->json([
            'count' => $preview->count(),
            'data' => $preview,
            'notice' => __('lf.LF_course_cohort_schedule_preview_notice'),
        ]);
    }

    private function cohort(int $customerId, int $cohortId): object
    {
        return DB::table('core_course_cohorts as cohorts')
            ->leftJoin('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'cohorts.product_id')
                    ->where('products.customer_id', $customerId);
            })
            ->where('cohorts.customer_id', $customerId)
            ->where('cohorts.id', $cohortId)
            ->firstOrFail([
                'cohorts.*', 'products.title as product_title', 'products.product_code',
            ]);
    }

    private function schedule(int $customerId, int $cohortId, int $scheduleId): object
    {
        return DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->where('id', $scheduleId)
            ->firstOrFail();
    }
}

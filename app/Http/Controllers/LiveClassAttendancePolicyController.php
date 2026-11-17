<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Services\LiveClassAttendancePolicies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The tenant's attendance policies and their assignment to Cohorts (ADR-0002 § Attendance Policy And Recording
 * Amendment, part A). Only `customer_admin` manages them.
 */
final class LiveClassAttendancePolicyController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    /** Cohorts a policy can be applied to: an archived Cohort is closed for good. */
    private const ASSIGNABLE_COHORT_STATUSES = ['draft', 'active', 'completed'];

    public function __construct(private readonly LiveClassAttendancePolicies $policies) {}

    public function index(Request $request): View
    {
        $customerId = $this->authorizeAdmin($request);
        $default = $this->policies->defaultFor($customerId);

        $usage = DB::table('core_course_cohorts')->where('customer_id', $customerId)
            ->whereIn('status', self::ASSIGNABLE_COHORT_STATUSES)
            ->selectRaw('attendance_policy_id, count(*) as cohorts')->groupBy('attendance_policy_id')
            ->pluck('cohorts', 'attendance_policy_id');
        // Any status: an archived Cohort still holds its policy, and the foreign key keeps that policy from being deleted.
        $assigned = DB::table('core_course_cohorts')->where('customer_id', $customerId)->whereNotNull('attendance_policy_id')
            ->selectRaw('attendance_policy_id, count(*) as cohorts')->groupBy('attendance_policy_id')
            ->pluck('cohorts', 'attendance_policy_id');
        $rows = DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $customerId)
            ->orderByRaw('is_default is null')->orderBy('name')->get()
            ->each(function (object $policy) use ($usage, $assigned): void {
                // A Cohort without a policy of its own uses the default one.
                $policy->cohort_count = (int) $usage->get($policy->id, 0) + ($policy->is_default ? (int) $usage->get('', 0) : 0);
                $policy->assigned_count = (int) $assigned->get($policy->id, 0);
            });

        $form = in_array($request->query('policy_form'), ['edit', 'apply'], true) ? $request->query('policy_form') : null;
        $selected = $form ? $rows->firstWhere('id', (int) $request->query('policy_id')) : null;
        abort_if($form && ! $selected, 404);

        return view('liveclass-attendance-policies.index', [
            'policies' => $rows,
            'defaultPolicy' => $default,
            'formMode' => $form,
            'formPolicy' => $selected,
            'applyCohorts' => $form === 'apply' ? $this->cohortsFor($customerId, $rows) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $values = $this->validated($request, $customerId, null);

        DB::table(LiveClassAttendancePolicies::TABLE)->insert($values + [
            'customer_id' => $customerId, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('admin.attendance-policies.index')
            ->with('success', __('lf.LF_attendance_policy_created'));
    }

    public function update(Request $request, int $policy): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $row = $this->policies->find($customerId, $policy) ?? abort(404);
        $values = $this->validated($request, $customerId, $row);

        DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $customerId)->where('id', $row->id)
            ->update($values + ['updated_by' => $request->user()->id, 'updated_at' => now()]);

        return redirect()->route('admin.attendance-policies.index')
            ->with('success', __('lf.LF_attendance_policy_updated'));
    }

    public function destroy(Request $request, int $policy): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $back = redirect()->route('admin.attendance-policies.index');

        $error = DB::transaction(function () use ($customerId, $policy): ?string {
            $row = DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $customerId)->where('id', $policy)
                ->lockForUpdate()->first() ?? abort(404);
            if ($row->is_default) {
                return __('lf.LF_attendance_policy_delete_default');
            }
            // Any Cohort still pointing here, archived ones included: the foreign key would refuse the delete.
            if (DB::table('core_course_cohorts')->where('customer_id', $customerId)->where('attendance_policy_id', $row->id)->exists()) {
                return __('lf.LF_attendance_policy_delete_assigned');
            }
            DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $customerId)->where('id', $row->id)->delete();

            return null;
        });

        return $error ? $back->withErrors(['policy' => $error]) : $back->with('success', __('lf.LF_attendance_policy_deleted'));
    }

    /**
     * Applies one policy to the ticked Cohorts and returns to the default the Cohorts unticked from it. Only the
     * Cohorts the form showed are touched, so a Cohort created meanwhile keeps what it has.
     */
    public function apply(Request $request, int $policy): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $row = $this->policies->find($customerId, $policy) ?? abort(404);
        $validated = $request->validate([
            'shown_cohort_ids' => ['present', 'array'],
            'shown_cohort_ids.*' => ['integer'],
            'cohort_ids' => ['nullable', 'array'],
            'cohort_ids.*' => ['integer'],
        ]);
        $shown = array_map('intval', $validated['shown_cohort_ids']);
        $ticked = array_values(array_intersect(array_map('intval', $validated['cohort_ids'] ?? []), $shown));

        $changed = DB::transaction(function () use ($customerId, $row, $shown, $ticked): int {
            $cohorts = DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->whereIn('status', self::ASSIGNABLE_COHORT_STATUSES)->whereIn('id', $shown)
                ->lockForUpdate()->get(['id', 'attendance_policy_id']);
            // On the default policy a tick means "back to the default"; its own Cohorts cannot be unticked there.
            $target = $row->is_default ? null : (int) $row->id;
            $changed = 0;
            foreach ($cohorts as $cohort) {
                $current = $cohort->attendance_policy_id === null ? null : (int) $cohort->attendance_policy_id;
                $new = match (true) {
                    in_array((int) $cohort->id, $ticked, true) => $target,
                    ! $row->is_default && $current === (int) $row->id => null,
                    default => $current,
                };
                if ($new !== $current) {
                    DB::table('core_course_cohorts')->where('customer_id', $customerId)->where('id', $cohort->id)
                        ->update(['attendance_policy_id' => $new, 'updated_at' => now()]);
                    $changed++;
                }
            }

            return $changed;
        });

        return redirect()->route('admin.attendance-policies.index')
            ->with('success', trans_choice('lf.LF_attendance_policy_applied', $changed, ['count' => $changed]));
    }

    /** @return array<string, mixed> the columns of a policy, validated against the documented limits */
    private function validated(Request $request, int $customerId, ?object $existing): array
    {
        $bag = $existing ? 'policyEdit' : 'policyCreate';
        $validator = validator($request->only([
            'name', 'opens_before_minutes', 'late_after_minutes', 'absent_unlimited', 'absent_after_minutes',
            'auto_absent_after_minutes', 'count_from_actual_start', 'offline_recording', 'online_recording',
            'makeup_enabled', 'makeup_unlimited', 'makeup_within_days', 'teacher_edit_days',
        ]), [
            'name' => ['required', 'string', 'max:150', Rule::unique(LiveClassAttendancePolicies::TABLE, 'name')
                ->where('customer_id', $customerId)->ignore($existing?->id)],
            'opens_before_minutes' => ['required', 'integer', 'between:0,240'],
            'late_after_minutes' => ['required', 'integer', 'between:0,240'],
            'absent_unlimited' => ['nullable', 'boolean'],
            'absent_after_minutes' => ['exclude_if:absent_unlimited,1', 'required', 'integer', 'gte:late_after_minutes', 'max:480'],
            'auto_absent_after_minutes' => ['required', 'integer', 'between:0,1440'],
            'count_from_actual_start' => ['nullable', 'boolean'],
            'offline_recording' => ['required', Rule::in(LiveClassAttendancePolicies::RECORDING_MODES)],
            'online_recording' => ['required', Rule::in(LiveClassAttendancePolicies::RECORDING_MODES)],
            'makeup_enabled' => ['nullable', 'boolean'],
            'makeup_unlimited' => ['nullable', 'boolean'],
            'makeup_within_days' => ['exclude_if:makeup_unlimited,1', 'required', 'integer', 'between:1,365'],
            'teacher_edit_days' => ['required', 'integer', 'between:0,365'],
        ], [], collect([
            'name', 'opens_before_minutes', 'late_after_minutes', 'absent_after_minutes', 'auto_absent_after_minutes',
            'offline_recording', 'online_recording', 'makeup_within_days', 'teacher_edit_days',
        ])->mapWithKeys(fn (string $field): array => [$field => __('lf.LF_attendance_policy_field_'.$field)])->all());

        if ($validator->fails()) {
            throw (new ValidationException($validator))->errorBag($bag);
        }
        $data = $validator->validated();

        return [
            'name' => trim($data['name']),
            'opens_before_minutes' => (int) $data['opens_before_minutes'],
            'late_after_minutes' => (int) $data['late_after_minutes'],
            'absent_after_minutes' => isset($data['absent_after_minutes']) ? (int) $data['absent_after_minutes'] : null,
            'auto_absent_after_minutes' => (int) $data['auto_absent_after_minutes'],
            'count_from_actual_start' => (bool) ($data['count_from_actual_start'] ?? false),
            'offline_recording' => $data['offline_recording'],
            'online_recording' => $data['online_recording'],
            'makeup_enabled' => (bool) ($data['makeup_enabled'] ?? false),
            'makeup_within_days' => isset($data['makeup_within_days']) ? (int) $data['makeup_within_days'] : null,
            'teacher_edit_days' => (int) $data['teacher_edit_days'],
        ];
    }

    /** The Cohorts offered in "Áp dụng cho lớp", each with the policy it uses now. */
    private function cohortsFor(int $customerId, $policies)
    {
        $names = $policies->pluck('name', 'id');
        $default = $policies->firstWhere('is_default', 1);

        return DB::table('core_course_cohorts')->where('customer_id', $customerId)
            ->whereIn('status', self::ASSIGNABLE_COHORT_STATUSES)
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'status', 'attendance_policy_id'])
            ->each(function (object $cohort) use ($names, $default): void {
                $cohort->policy_id = $cohort->attendance_policy_id === null ? (int) $default->id : (int) $cohort->attendance_policy_id;
                $cohort->policy_name = $names->get($cohort->policy_id, $default->name);
            });
    }
}

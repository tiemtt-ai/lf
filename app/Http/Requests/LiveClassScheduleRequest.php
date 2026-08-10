<?php

namespace App\Http\Requests;

use App\Services\CourseCohortMutationPolicy;
use App\Support\TenantContext;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LiveClassScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $customerId = TenantContext::customerId();
        if (! $customerId || $this->user()?->role !== 'customer_admin') {
            return false;
        }

        $cohort = DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)
            ->where('id', (int) $this->route('cohort'))
            ->first(['id', 'status']);
        if (! $cohort || ! CourseCohortMutationPolicy::canMutate($cohort)) {
            return false;
        }

        $scheduleId = (int) ($this->route('schedule') ?? 0);

        return $scheduleId === 0 || DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohort->id)
            ->where('id', $scheduleId)
            ->exists();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'timezone' => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers())],
            'slots' => ['required', 'array', 'min:1', 'max:50'],
            'slots.*.weekday' => ['required', 'integer', 'between:1,7'],
            'slots.*.start_time' => ['required', 'date_format:H:i'],
            'slots.*.end_time' => ['required', 'date_format:H:i'],
            'exclusions' => ['nullable', 'array', 'max:366'],
            'exclusions.*.excluded_on' => ['required', 'date_format:Y-m-d', 'distinct'],
            'exclusions.*.reason' => ['nullable', 'string', 'max:500'],
            'customer_id' => ['prohibited'],
            'cohort_id' => ['prohibited'],
            'status' => ['prohibited'],
            'deleted_at' => ['prohibited'],
            'default_teacher_id' => ['prohibited'],
            'default_room_id' => ['prohibited'],
            'delivery_mode' => ['prohibited'],
            'recurrence' => ['prohibited'],
            'session_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['starts_on', 'ends_on', 'slots', 'timezone'])) {
                return;
            }

            $customerId = TenantContext::customerId();
            $cohort = DB::table('core_course_cohorts')
                ->where('customer_id', $customerId)
                ->where('id', (int) $this->route('cohort'))
                ->first(['start_date', 'end_date']);
            if (! $cohort?->start_date || ! $cohort?->end_date) {
                $validator->errors()->add('starts_on', __('lf.LF_course_cohort_schedule_validation_cohort_period'));

                return;
            }

            $startsOn = Carbon::createFromFormat('Y-m-d', (string) $this->input('starts_on'))->startOfDay();
            $endsOn = Carbon::createFromFormat('Y-m-d', (string) $this->input('ends_on'))->startOfDay();
            if ($startsOn->lt(Carbon::parse($cohort->start_date)->startOfDay())
                || $endsOn->gt(Carbon::parse($cohort->end_date)->startOfDay())) {
                $validator->errors()->add('starts_on', __('lf.LF_course_cohort_schedule_validation_outside_cohort', [
                    'from' => Carbon::parse($cohort->start_date)->format('d/m/Y'),
                    'to' => Carbon::parse($cohort->end_date)->format('d/m/Y'),
                ]));
            }

            $slotsByWeekday = collect($this->input('slots', []))->groupBy('weekday');
            foreach ($slotsByWeekday as $slots) {
                $normalized = $slots->map(fn (array $slot): array => [
                    'start' => $this->minutes((string) ($slot['start_time'] ?? '')),
                    'end' => $this->minutes((string) ($slot['end_time'] ?? '')),
                ])->sortBy('start')->values();

                foreach ($normalized as $index => $slot) {
                    if ($slot['start'] === null || $slot['end'] === null) {
                        continue;
                    }
                    if ($slot['end'] <= $slot['start']) {
                        $validator->errors()->add("slots.{$index}.end_time", __('lf.LF_course_cohort_schedule_validation_time_order'));
                    }
                    if ($index > 0 && $slot['start'] < $normalized[$index - 1]['end']) {
                        $validator->errors()->add('slots', __('lf.LF_course_cohort_schedule_validation_overlap'));
                        break 2;
                    }
                }
            }

            foreach ($this->input('exclusions', []) as $index => $exclusion) {
                $date = $exclusion['excluded_on'] ?? null;
                if (! $date) {
                    continue;
                }
                $excludedOn = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
                if ($excludedOn->lt($startsOn) || $excludedOn->gt($endsOn)) {
                    $validator->errors()->add("exclusions.{$index}.excluded_on", __('lf.LF_course_cohort_schedule_validation_exclusion_range'));
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => __('lf.LF_course_cohort_schedule_name'),
            'starts_on' => __('lf.LF_course_cohort_schedule_starts_on'),
            'ends_on' => __('lf.LF_course_cohort_schedule_ends_on'),
            'timezone' => __('lf.LF_course_cohort_schedule_timezone'),
            'slots' => __('lf.LF_course_cohort_schedule_slots'),
            'slots.*.weekday' => __('lf.LF_course_cohort_schedule_weekday'),
            'slots.*.start_time' => __('lf.LF_course_cohort_schedule_start_time'),
            'slots.*.end_time' => __('lf.LF_course_cohort_schedule_end_time'),
            'exclusions.*.excluded_on' => __('lf.LF_course_cohort_schedule_excluded_on'),
            'exclusions.*.reason' => __('lf.LF_course_cohort_schedule_reason'),
        ];
    }

    private function minutes(string $time): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }
}

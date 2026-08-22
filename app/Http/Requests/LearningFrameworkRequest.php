<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a new Learning Framework.
 *
 * The mastery scale is checked here for structure only — an array of levels,
 * each with a non-empty key and a numeric threshold. The value domain itself
 * (thresholds inside [0, 1], strictly increasing, lowest exactly zero) stays in
 * LearningFrameworkAuthoringService::normalizeScale(). That rule was written to
 * close N3 and restating it here would create two places to update when it next
 * moves; the service error is mapped to a readable message at the boundary
 * instead.
 */
class LearningFrameworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'customer_admin';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'mastery_scale_key' => ['required', 'string', 'max:100'],
            'mastery_scale_version' => ['required', 'string', 'max:50'],
            'mastery_scale' => ['required', 'array'],
            'mastery_scale.levels' => ['required', 'array', 'min:2'],
            'mastery_scale.levels.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'mastery_scale.levels.*.threshold' => ['required', 'numeric'],

            'customer_id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'archived_by' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function command(): array
    {
        return $this->validated();
    }
}

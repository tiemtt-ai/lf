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
        // `section` names the edit form that saved (2026-11-10): `identity` sends only name and
        // description, `scale` only the scale. Create and a full update send everything.
        $section = $this->input('section');
        $identity = $section === 'scale' ? ['exclude'] : [];
        $scale = $section === 'identity' ? ['exclude'] : [];

        return [
            'section' => ['nullable', 'string', 'in:identity,scale'],
            // Blank on create means "derive it from the name"; a full update resends the stored code.
            'code' => [...$identity, 'nullable', 'string', 'max:100'],
            'name' => [...$identity, 'required', 'string', 'max:255'],
            'description' => [...$identity, 'nullable', 'string', 'max:5000'],
            'mastery_scale_key' => [...$scale, 'required', 'string', 'max:100'],
            'mastery_scale_version' => [...$scale, 'required', 'string', 'max:50'],
            'mastery_scale' => [...$scale, 'required', 'array'],
            'mastery_scale.levels' => [...$scale, 'required', 'array', 'min:2'],
            'mastery_scale.levels.*.key' => [...$scale, 'required', 'string', 'max:100', 'distinct'],
            'mastery_scale.levels.*.threshold' => [...$scale, 'required', 'numeric'],

            'customer_id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'archived_by' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'mastery_scale.levels.*.key' => __('lf.LF_learning_simple_scale_level_code'),
            'mastery_scale.levels.*.threshold' => __('lf.LF_learning_threshold_percent'),
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

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a new draft Framework Version.
 *
 * `version_number` is prohibited rather than optional: the service derives it
 * under the Framework row lock, which is what keeps concurrent drafts off the
 * same number. A caller-supplied value would race idx_lrn_004.
 *
 * The lifecycle columns are prohibited because a version may only be born
 * `draft_snapshot` — trg_lrn_fw_versions_bi_validate refuses anything else, and
 * a request that can express the refused state is a request that will one day
 * be sent.
 */
class LearningFrameworkVersionRequest extends FormRequest
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
            'framework_id' => ['required', 'integer', 'min:1'],
            'version_code' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            'customer_id' => ['prohibited'],
            'version_number' => ['prohibited'],
            'status' => ['prohibited'],
            'published_at' => ['prohibited'],
            'published_by' => ['prohibited'],
            'deprecated_at' => ['prohibited'],
            'deprecated_by' => ['prohibited'],
            'archived_at' => ['prohibited'],
            'archived_by' => ['prohibited'],
            'mastery_scale_snapshot' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
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

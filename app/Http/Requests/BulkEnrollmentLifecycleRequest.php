<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkEnrollmentLifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'customer_admin';
    }

    public function rules(): array
    {
        return [
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'enrollment_ids.*' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['suspend', 'reactivate', 'cancel'])],
            'status' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'student_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'version_id' => ['prohibited'],
            'source' => ['prohibited'],
            'source_id' => ['prohibited'],
            'enrolled_by' => ['prohibited'],
            'enrolled_at' => ['prohibited'],
            'access_starts_at' => ['prohibited'],
            'access_ends_at' => ['prohibited'],
            'review_starts_at' => ['prohibited'],
            'review_ends_at' => ['prohibited'],
            'notes' => ['prohibited'],
        ];
    }
}

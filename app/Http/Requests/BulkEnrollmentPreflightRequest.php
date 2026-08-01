<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkEnrollmentPreflightRequest extends FormRequest
{
    public const MAX_PAIRS = 100;

    public function authorize(): bool
    {
        return $this->user()?->role === 'customer_admin';
    }

    public function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'min:1', 'max:100'],
            'student_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'reenrollment_confirmations' => ['sometimes', 'array'],
            'reenrollment_confirmations.*.student_id' => ['required', 'integer', 'min:1'],
            'reenrollment_confirmations.*.product_id' => ['required', 'integer', 'min:1'],
            'reenrollment_confirmations.*.previous_enrollment_id' => ['required', 'integer', 'min:1'],
            'configuration' => ['sometimes', 'array'],
            'configuration.access_starts_at' => ['prohibited'],
            'configuration.access_ends_at' => ['prohibited'],
            'configuration.review_starts_at' => ['prohibited'],
            'configuration.review_ends_at' => ['prohibited'],
            'configuration.enrolled_at' => ['nullable', 'date'],
            'configuration.notes' => ['nullable', 'string'],
            'finalize' => ['sometimes', 'boolean'],
            'customer_id' => ['prohibited'],
            'admin_id' => ['prohibited'],
            'version_id' => ['prohibited'],
            'product_id' => ['prohibited'],
            'student_id' => ['prohibited'],
            'source' => ['prohibited'],
            'status' => ['prohibited'],
            'enrolled_at' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $configuration = (array) $this->input('configuration', []);
        foreach (['enrolled_at', 'notes'] as $field) {
            $value = $configuration[$field] ?? null;
            $configuration[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }

        $this->merge(['configuration' => $configuration]);
    }

    public function after(): array
    {
        return [function ($validator): void {
            $studentCount = count((array) $this->input('student_ids', []));
            $productCount = count((array) $this->input('product_ids', []));
            if ($studentCount * $productCount > self::MAX_PAIRS) {
                $validator->errors()->add('pair_count', __('lf.LF_bulk_enrollment_validation_pair_limit'));
            }

            $keys = [];
            foreach ((array) $this->input('reenrollment_confirmations', []) as $confirmation) {
                $key = ($confirmation['student_id'] ?? '').':'.($confirmation['product_id'] ?? '');
                if (isset($keys[$key])) {
                    $validator->errors()->add('reenrollment_confirmations', __('lf.LF_bulk_enrollment_validation_confirmation'));
                    break;
                }
                $keys[$key] = true;
            }
        }];
    }
}

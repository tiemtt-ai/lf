<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkEnrollmentUpdateRequest extends FormRequest
{
    private const FIELDS = ['access_starts_at', 'access_ends_at', 'review_starts_at', 'review_ends_at', 'notes'];

    public function authorize(): bool
    {
        return $this->user()?->role === 'customer_admin';
    }

    public function rules(): array
    {
        $rules = [
            'enrollment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'enrollment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'customer_id' => ['prohibited'], 'student_id' => ['prohibited'],
            'product_id' => ['prohibited'], 'version_id' => ['prohibited'],
            'source' => ['prohibited'], 'status' => ['prohibited'], 'enrolled_at' => ['prohibited'],
        ];
        foreach (self::FIELDS as $field) {
            $rules[$field.'_action'] = ['required', Rule::in(['preserve', 'set', 'clear'])];
            $rules[$field.'_value'] = $field === 'notes'
                ? ['nullable', Rule::requiredIf(fn () => $this->input($field.'_action') === 'set'), 'string']
                : ['nullable', Rule::requiredIf(fn () => $this->input($field.'_action') === 'set'), 'date'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (collect(self::FIELDS)->every(fn (string $field): bool => $this->input($field.'_action') === 'preserve')) {
                $validator->errors()->add('bulk_update', __('lf.LF_course_enrollment_bulk_update_required'));
            }
        }];
    }

    public function changes(): array
    {
        return collect(self::FIELDS)->mapWithKeys(fn (string $field): array => [$field => [
            'action' => $this->validated($field.'_action'),
            'value' => $this->validated($field.'_value'),
        ]])->all();
    }
}

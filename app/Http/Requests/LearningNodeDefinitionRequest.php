<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a stable Node Definition.
 *
 * `node_type` is listed here as well as in the service because it is a closed
 * vocabulary frozen by chk_lrn_006, not a rule in motion: the caller deserves a
 * field error rather than a domain exception. The service guard stays.
 */
class LearningNodeDefinitionRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:120'],
            'node_type' => ['required', 'string', 'in:objective,concept,competency'],
            'canonical_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            'customer_id' => ['prohibited'],
            'status' => ['prohibited'],
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

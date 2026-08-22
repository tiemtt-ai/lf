<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a versioned Node inside a draft Framework Version.
 *
 * Every snapshot column is prohibited. A Node copies its code, name and
 * description from the Definition it points at, and a request able to state
 * them differently would let the snapshot disagree with its source in a row
 * that trg_lrn_nodes_bu_immutable freezes the moment the version is published.
 */
class LearningNodeRequest extends FormRequest
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
            'framework_version_id' => ['required', 'integer', 'min:1'],
            'node_definition_id' => ['required', 'integer', 'min:1'],
            'sequence' => ['sometimes', 'integer', 'min:1'],
            'criteria_json' => ['nullable', 'json'],

            'customer_id' => ['prohibited'],
            'framework_id' => ['prohibited'],
            'code_snapshot' => ['prohibited'],
            'name_snapshot' => ['prohibited'],
            'description_snapshot' => ['prohibited'],
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
        $command = $this->validated();

        if (array_key_exists('criteria_json', $command)) {
            $command['criteria'] = filled($command['criteria_json'])
                ? json_decode($command['criteria_json'], true, 512, JSON_THROW_ON_ERROR)
                : null;
            unset($command['criteria_json']);
        }

        return $command;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! filled($this->input('criteria_json'))) {
                return;
            }

            $decoded = json_decode((string) $this->input('criteria_json'), true);
            if (! is_array($decoded)) {
                $validator->errors()->add('criteria_json', __('validation.array', ['attribute' => 'criteria']));
            }
        });
    }
}

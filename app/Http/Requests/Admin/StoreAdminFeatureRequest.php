<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('features', 'key'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'credit_cost' => ['required', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{key: string, name: string, credit_cost: int, is_active: bool}
     */
    public function featurePayload(): array
    {
        $validated = $this->validated();

        return [
            'key' => $validated['key'],
            'name' => $validated['name'],
            'credit_cost' => (int) $validated['credit_cost'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAdminFeatureRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'credit_cost' => ['sometimes', 'required', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAny(['name', 'credit_cost', 'is_active'])) {
                $validator->errors()->add(
                    'body',
                    'Provide at least one of: name, credit_cost, is_active.'
                );
            }
        });
    }

    /**
     * @return array{name?: string, credit_cost?: int, is_active?: bool}
     */
    public function featurePatch(): array
    {
        return $this->validated();
    }
}

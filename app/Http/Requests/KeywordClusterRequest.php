<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KeywordClusterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'keyword' => ['required', 'string', 'min:1', 'max:255'],
            'language_code' => ['sometimes', 'string', 'min:2', 'max:8'],
            'location_code' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'language_code' => strtolower((string) $this->input('language_code', 'en')),
            'location_code' => (int) $this->input('location_code', 2840),
        ]);
    }
}

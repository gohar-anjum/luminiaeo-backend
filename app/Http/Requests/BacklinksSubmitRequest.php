<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BacklinksSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => [
                'required',
                'url',
                'max:255',
            ],
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'domain.required' => 'Domain is required',
            'domain.url' => 'Domain must be a valid URL',
            'domain.max' => 'Domain must not exceed 255 characters',
            'limit.integer' => 'Limit must be an integer',
            'limit.min' => 'Limit must be at least 1',
            'limit.max' => 'Limit must not exceed 1000',
        ];
    }

    protected function prepareForValidation(): void
    {

        if ($this->has('domain')) {
            $domain = $this->input('domain');
            if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
                $this->merge(['domain' => 'https://' . $domain]);
            }
        }
    }
}

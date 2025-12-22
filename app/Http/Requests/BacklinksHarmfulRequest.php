<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BacklinksHarmfulRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'url', 'max:255'],
            'risk_levels' => ['sometimes', 'array'],
            'risk_levels.*' => ['string', 'in:low,medium,high,critical'],
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

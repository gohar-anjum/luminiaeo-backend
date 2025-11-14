<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchVolumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keywords' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'keywords.*' => [
                'required',
                'string',
                'max:255',
            ],
            'language_code' => [
                'sometimes',
                'string',
                'size:2',
            ],
            'location_code' => [
                'sometimes',
                'integer',
                'min:1',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'keywords.required' => 'Keywords are required',
            'keywords.array' => 'Keywords must be an array',
            'keywords.min' => 'At least one keyword is required',
            'keywords.max' => 'Maximum 100 keywords allowed',
            'keywords.*.required' => 'Each keyword is required',
            'keywords.*.string' => 'Each keyword must be a string',
            'keywords.*.max' => 'Each keyword must not exceed 255 characters',
            'language_code.size' => 'Language code must be 2 characters',
            'location_code.integer' => 'Location code must be an integer',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);
        
        // Set defaults
        $validated['language_code'] = $validated['language_code'] ?? 'en';
        $validated['location_code'] = $validated['location_code'] ?? 2840; // United States
        
        return $validated;
    }
}


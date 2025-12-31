<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KeywordsForSitePlannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target' => [
                'required',
                'string',
                'max:255',
            ],
            'location_code' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'language_code' => [
                'sometimes',
                'string',
                'size:2',
                'regex:/^[a-z]{2}$/i',
            ],
            'search_partners' => [
                'sometimes',
                'boolean',
            ],
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:1000',
            ],
            'date_from' => [
                'sometimes',
                'date',
            ],
            'date_to' => [
                'sometimes',
                'date',
                'after_or_equal:date_from',
            ],
            'include_serp_info' => [
                'sometimes',
                'boolean',
            ],
            'tag' => [
                'sometimes',
                'string',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target.required' => 'The target website/domain is required.',
            'target.string' => 'The target must be a string.',
            'target.max' => 'The target must not exceed 255 characters.',
            'location_code.integer' => 'Location code must be an integer.',
            'location_code.min' => 'Location code must be at least 1.',
            'language_code.size' => 'Language code must be exactly 2 characters.',
            'language_code.regex' => 'Language code must be a valid ISO 639-1 code.',
            'search_partners.boolean' => 'Search partners must be a boolean.',
            'limit.integer' => 'Limit must be an integer.',
            'limit.min' => 'Limit must be at least 1.',
            'limit.max' => 'Limit must not exceed 1000.',
            'date_from.date' => 'Date from must be a valid date.',
            'date_to.date' => 'Date to must be a valid date.',
            'date_to.after_or_equal' => 'Date to must be after or equal to date from.',
            'include_serp_info.boolean' => 'Include SERP info must be a boolean.',
            'tag.string' => 'Tag must be a string.',
            'tag.max' => 'Tag must not exceed 100 characters.',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        // Apply defaults
        $validated['location_code'] = $validated['location_code'] ?? 2840;
        $validated['language_code'] = $validated['language_code'] ?? 'en';
        $validated['search_partners'] = $validated['search_partners'] ?? true;
        $validated['date_from'] = $validated['date_from'] ?? null;
        $validated['date_to'] = $validated['date_to'] ?? null;
        $validated['include_serp_info'] = $validated['include_serp_info'] ?? false;
        $validated['tag'] = $validated['tag'] ?? null;

        return $validated;
    }
}


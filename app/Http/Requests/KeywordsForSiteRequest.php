<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KeywordsForSiteRequest extends FormRequest
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
            ],
            'search_partners' => [
                'sometimes',
                'boolean',
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
            'target.required' => 'Target website/domain is required',
            'target.string' => 'Target must be a string',
            'target.max' => 'Target must not exceed 255 characters',
            'location_code.integer' => 'Location code must be an integer',
            'location_code.min' => 'Location code must be at least 1',
            'language_code.size' => 'Language code must be 2 characters',
            'search_partners.boolean' => 'Search partners must be a boolean',
            'date_from.date' => 'Date from must be a valid date',
            'date_to.date' => 'Date to must be a valid date',
            'date_to.after_or_equal' => 'Date to must be after or equal to date from',
            'include_serp_info.boolean' => 'Include SERP info must be a boolean',
            'tag.string' => 'Tag must be a string',
            'tag.max' => 'Tag must not exceed 100 characters',
            'limit.integer' => 'Limit must be an integer',
            'limit.min' => 'Limit must be at least 1',
            'limit.max' => 'Limit must not exceed 1000',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        $validated['location_code'] = $validated['location_code'] ?? 2840;
        $validated['language_code'] = $validated['language_code'] ?? 'en';
        $validated['search_partners'] = $validated['search_partners'] ?? true;

        return $validated;
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KeywordResearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => [
                'required',
                'string',
                'max:255',
                'min:1',
            ],
            'project_id' => [
                'nullable',
                'integer',
                'exists:projects,id',
            ],
            'language_code' => [
                'nullable',
                'string',
                'size:2',
                'regex:/^[a-z]{2}$/i',
            ],
            'geo_target_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'max_keywords' => [
                'nullable',
                'integer',
                'min:1',
                'max:5000',
            ],
            'enable_google_planner' => [
                'nullable',
                'boolean',
            ],
            'enable_scraper' => [
                'nullable',
                'boolean',
            ],
            'enable_clustering' => [
                'nullable',
                'boolean',
            ],
            'enable_intent_scoring' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'query.required' => 'The search query is required.',
            'query.string' => 'The search query must be a string.',
            'query.max' => 'The search query must not exceed 255 characters.',
            'query.min' => 'The search query must be at least 1 character.',
            'project_id.exists' => 'The selected project does not exist.',
            'language_code.size' => 'Language code must be exactly 2 characters.',
            'language_code.regex' => 'Language code must be a valid ISO 639-1 code.',
            'geo_target_id.integer' => 'Geo target ID must be an integer.',
            'geo_target_id.min' => 'Geo target ID must be at least 1.',
            'max_keywords.integer' => 'Max keywords must be an integer.',
            'max_keywords.min' => 'Max keywords must be at least 1.',
            'max_keywords.max' => 'Max keywords must not exceed 5000.',
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        $validated['language_code'] = $validated['language_code'] ?? 'en';
        $validated['geo_target_id'] = $validated['geo_target_id'] ?? 2840;
        $validated['enable_google_planner'] = $validated['enable_google_planner'] ?? true;
        $validated['enable_scraper'] = $validated['enable_scraper'] ?? true;
        $validated['enable_clustering'] = $validated['enable_clustering'] ?? true;
        $validated['enable_intent_scoring'] = $validated['enable_intent_scoring'] ?? true;

        return $validated;
    }
}

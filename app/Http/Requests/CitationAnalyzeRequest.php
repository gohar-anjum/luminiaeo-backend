<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CitationAnalyzeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxQueries = config('citations.max_queries', 150);
        $defaultQueries = config('citations.default_queries', 100);

        return [
            'url' => [
                'required',
                'url',
                'max:2048',
            ],
            'num_queries' => [
                'nullable',
                'integer',
                'min:10',
                "max:{$maxQueries}",
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'The URL is required.',
            'url.url' => 'The URL must be a valid URL.',
            'url.max' => 'The URL must not exceed 2048 characters.',
            'num_queries.integer' => 'Number of queries must be an integer.',
            'num_queries.min' => 'Number of queries must be at least 10.',
            'num_queries.max' => "Number of queries must not exceed " . config('citations.max_queries', 150) . ".",
        ];
    }

    public function validated($key = null, $default = null)
    {
        $validated = parent::validated($key, $default);

        if (!isset($validated['num_queries'])) {
            $validated['num_queries'] = config('citations.default_queries', 100);
        }

        return $validated;
    }
}

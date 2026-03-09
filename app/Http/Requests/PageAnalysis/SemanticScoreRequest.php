<?php

namespace App\Http\Requests\PageAnalysis;

use Illuminate\Foundation\Http\FormRequest;

class SemanticScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:2048'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ];
    }
}

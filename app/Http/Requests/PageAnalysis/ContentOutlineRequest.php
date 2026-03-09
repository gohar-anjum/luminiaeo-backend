<?php

namespace App\Http\Requests\PageAnalysis;

use Illuminate\Foundation\Http\FormRequest;

class ContentOutlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => ['required', 'string', 'max:255'],
            'tone' => ['nullable', 'string', 'in:professional,casual,academic,persuasive,informative'],
        ];
    }
}

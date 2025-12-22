<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FaqGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'input' => [
                'required',
                'string',
                'max:2048',
            ],
            'options' => [
                'sometimes',
                'array',
            ],
            'options.temperature' => [
                'sometimes',
                'numeric',
                'min:0',
                'max:1',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'input.required' => 'Input field is required (URL or topic)',
            'input.string' => 'Input must be a string',
            'input.max' => 'Input must not exceed 2048 characters',
            'options.array' => 'Options must be an array',
            'options.temperature.numeric' => 'Temperature must be a number',
            'options.temperature.min' => 'Temperature must be at least 0',
            'options.temperature.max' => 'Temperature must be at most 1',
        ];
    }
}

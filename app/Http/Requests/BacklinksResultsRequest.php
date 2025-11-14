<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BacklinksResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id' => [
                'required',
                'string',
                'max:255',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'task_id.required' => 'Task ID is required',
            'task_id.string' => 'Task ID must be a string',
            'task_id.max' => 'Task ID must not exceed 255 characters',
        ];
    }
}


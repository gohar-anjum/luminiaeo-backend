<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = auth()->id();

        return [
            'name' => 'sometimes|string|max:50|min:3|regex:/^[A-Za-z]+(?:\s[A-Za-z]+)*$/',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Name must be a string',
            'name.max' => 'Name must not exceed 50 characters',
            'name.min' => 'Name must be at least 3 characters',
            'name.regex' => 'Name must contain only letters',
            'email.email' => 'Email must be a valid email address',
            'email.unique' => 'This email is already taken',
            'email.max' => 'Email must not exceed 255 characters',
        ];
    }
}


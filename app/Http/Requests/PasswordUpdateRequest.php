<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|max:20|confirmed',
            'password_confirmation' => 'required|string|min:8',
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Current password is required',
            'password.required' => 'New password is required',
            'password.min' => 'New password must be at least 8 characters',
            'password.max' => 'New password must be less than 20 characters',
            'password.confirmed' => 'Password confirmation does not match',
            'password_confirmation.required' => 'Password confirmation is required',
        ];
    }
}


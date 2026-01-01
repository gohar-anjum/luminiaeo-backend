<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50|min:3|regex:/^[A-Za-z]+(?:\s[A-Za-z]+)*$/',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|max:20|confirmed',
            'password_confirmation' => 'required|string|min:8',
        ];
    }
    public function messages()
    {
        return [
            'name.required' => 'Name is required',
            'email.required' => 'Email is required',
            'password.required' => 'Password is required',
            'password.min' => 'Password must be at least 8 characters',
            'password.max' => 'Password must be less than 20 characters',
            'password.confirmed' => 'Password confirmation does not match',
            'name.regex' => 'No special characters and numbers allowed',
            'password_confirmation.required' => 'Password confirmation is required',
        ];
    }
}

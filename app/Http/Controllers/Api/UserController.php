<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordUpdateRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Services\ApiResponseModifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    private ApiResponseModifier $responseModifier;

    public function __construct(ApiResponseModifier $responseModifier)
    {
        $this->responseModifier = $responseModifier;
    }

    /**
     * Update user profile (name, email) or password based on request body
     * Can handle both profile and password updates in a single request
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->all();
        $hasPasswordUpdate = isset($data['current_password']) || isset($data['password']);
        $hasProfileUpdate = isset($data['name']) || isset($data['email']);
        $messages = [];

        // Validate password update if password fields are present
        if ($hasPasswordUpdate) {
            $passwordRequest = new PasswordUpdateRequest();
            $passwordRules = $passwordRequest->rules();
            $passwordValidator = Validator::make($data, $passwordRules);
            
            if ($passwordValidator->fails()) {
                return $this->responseModifier
                    ->setMessage($passwordValidator->errors()->first())
                    ->setResponseCode(422)
                    ->response();
            }

            // Verify current password
            if (!Hash::check($data['current_password'], $user->password)) {
                return $this->responseModifier
                    ->setMessage('Current password is incorrect')
                    ->setResponseCode(422)
                    ->response();
            }

            // Update password
            $user->password = Hash::make($data['password']);
            $messages[] = 'Password updated successfully';
        }

        // Validate and update profile if name/email fields are present
        if ($hasProfileUpdate) {
            $profileRequest = new ProfileUpdateRequest();
            $profileRules = $profileRequest->rules();
            $profileValidator = Validator::make($data, $profileRules);
            
            if ($profileValidator->fails()) {
                return $this->responseModifier
                    ->setMessage($profileValidator->errors()->first())
                    ->setResponseCode(422)
                    ->response();
            }

            $validated = $profileValidator->validated();

            // Update only provided fields
            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }

            if (isset($validated['email'])) {
                $user->email = $validated['email'];
            }

            $messages[] = 'Profile updated successfully';
        }

        // If no valid fields to update, return error
        if (!$hasPasswordUpdate && !$hasProfileUpdate) {
            return $this->responseModifier
                ->setMessage('No valid fields to update. Provide name, email, or password fields.')
                ->setResponseCode(422)
                ->response();
        }

        $user->save();

        $message = implode(' ', $messages);
        return $this->responseModifier
            ->setMessage($message)
            ->setData(['user' => $user->fresh()])
            ->response();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\ApiResponseModifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private ApiResponseModifier $responseModifier;

    public function __construct(ApiResponseModifier $responseModifier)
    {
        $this->responseModifier = $responseModifier;
    }

    public function login(Request $request)
    {
        if (Auth::attempt($request->only('email', 'password'))) {
            $token = auth()->user()->createToken("access-token");
            return $this->responseModifier->setData(['auth_token' => $token->plainTextToken, 'user' => \auth()->user()])->response();
        }
        return $this->responseModifier->setMessage('Invalid credentials')->setResponseCode(401)->response();
    }

    public function register(Request $request)
    {
        $rules = new RegisterRequest();
        $validate = Validator::make($request->all(), $rules->rules());
        if ($validate->fails()) {
            return $this->responseModifier->setMessage($validate->errors()->first())->setResponseCode(422)->response();
        }
        $validated = $validate->validated();
        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        return $this->responseModifier->setMessage('User created successfully')->response();
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        try {
            $email = $request->validated()['email'];
            $user = User::where('email', $email)->first();

            if (!$user) {
                return $this->responseModifier
                    ->setMessage('If that email address exists in our system, we have sent a password reset link.')
                    ->response();
            }

            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]
            );

            Mail::to($user->email)->send(new PasswordResetMail($token, $user->email));

            return $this->responseModifier
                ->setMessage('If that email address exists in our system, we have sent a password reset link.')
                ->response();
        } catch (\Exception $e) {
            return $this->responseModifier
                ->setMessage('Unable to send password reset email. Please try again later.')
                ->setResponseCode(500)
                ->response();
        }
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        try {
            $validated = $request->validated();
            $email = $validated['email'];
            $token = $validated['token'];
            $password = $validated['password'];

            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$passwordReset) {
                return $this->responseModifier
                    ->setMessage('Invalid or expired reset token.')
                    ->setResponseCode(400)
                    ->response();
            }

            if (now()->diffInMinutes($passwordReset->created_at) > 60) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
                return $this->responseModifier
                    ->setMessage('Reset token has expired. Please request a new password reset.')
                    ->setResponseCode(400)
                    ->response();
            }

            if (!Hash::check($token, $passwordReset->token)) {
                return $this->responseModifier
                    ->setMessage('Invalid reset token.')
                    ->setResponseCode(400)
                    ->response();
            }

            $user = User::where('email', $email)->first();
            if (!$user) {
                return $this->responseModifier
                    ->setMessage('User not found.')
                    ->setResponseCode(404)
                    ->response();
            }

            $user->update([
                'password' => Hash::make($password),
            ]);

            DB::table('password_reset_tokens')->where('email', $email)->delete();
            $user->tokens()->delete();

            return $this->responseModifier
                ->setMessage('Password has been reset successfully. You can now login with your new password.')
                ->response();
        } catch (\Exception $e) {
            return $this->responseModifier
                ->setMessage('Unable to reset password. Please try again later.')
                ->setResponseCode(500)
                ->response();
        }
    }
}

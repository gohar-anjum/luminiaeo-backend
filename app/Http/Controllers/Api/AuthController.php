<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\ApiResponseModifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
            return $this->responseModifier->setData(['auth_token' => $token->plainTextToken])->response();
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
}

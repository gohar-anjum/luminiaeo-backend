<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseModifier;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    public function __construct(
        private ApiResponseModifier $responseModifier,
        private WalletServiceInterface $walletService,
        private GoogleIdTokenVerifier $idTokenVerifier
    ) {}

    public function idTokenLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_token' => 'required|string',
        ], [
            'id_token.required' => 'A Google id_token is required.',
        ]);
        if ($validator->fails()) {
            return $this->responseModifier
                ->setMessage($validator->errors()->first())
                ->setResponseCode(422)
                ->response();
        }
        $idToken = $request->string('id_token')->toString();
        $payload = $this->idTokenVerifier->verify($idToken);
        if (! $payload) {
            return $this->responseModifier
                ->setMessage('Invalid Google id_token. Check GOOGLE_OAUTH_CLIENT_ID matches your app.')
                ->setResponseCode(401)
                ->response();
        }
        if (empty($payload['email']) || ! ($payload['email_verified'] ?? false)) {
            return $this->responseModifier
                ->setMessage('Google did not return a verified email for this account.')
                ->setResponseCode(401)
                ->response();
        }
        $sub = (string) ($payload['sub'] ?? '');
        $email = (string) $payload['email'];
        if ($sub === '' || $email === '') {
            return $this->responseModifier
                ->setMessage('Invalid Google sign-in response.')
                ->setResponseCode(401)
                ->response();
        }
        $user = User::query()->where('google_id', $sub)->first()
            ?? User::query()->where('email', $email)->first();
        if ($user) {
            if (! $user->is_admin && $user->suspended_at !== null) {
                return $this->responseModifier
                    ->setMessage('Your account has been suspended.')
                    ->setResponseCode(403)
                    ->response();
            }
            if ($user->google_id === null && $user->email === $email) {
                if ($user->is_admin) {
                    return $this->responseModifier
                        ->setMessage('This email is registered as an admin. Please sign in with a password.')
                        ->setResponseCode(409)
                        ->response();
                }
                $user->forceFill([
                    'google_id' => $sub,
                    'email_verified_at' => now(),
                ])->save();
            }
        } else {
            $name = (string) ($payload['name'] ?? explode('@', $email)[0]);
            if ($name === '' || $name === '0') {
                $name = 'User';
            }
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'google_id' => $sub,
                'email_verified_at' => now(),
            ]);
            $signupBonus = (int) config('billing.signup_bonus_credits', 10);
            if ($signupBonus > 0) {
                $this->walletService->addCredits($user, $signupBonus, 'bonus', [
                    'metadata' => ['reason' => 'signup_bonus', 'source' => 'google'],
                ]);
            }
        }
        $user = $user->fresh();
        $token = $user->createToken('access-token');

        return $this->responseModifier
            ->setData(['auth_token' => $token->plainTextToken, 'user' => $user])
            ->setMessage('Signed in with Google.')
            ->response();
    }
}

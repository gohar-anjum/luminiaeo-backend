<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerifiedUnlessWhitelisted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        if ($user->is_admin) {
            return $next($request);
        }
        if ($user->hasVerifiedEmail()) {
            return $next($request);
        }
        if ($this->isWhitelisted($request)) {
            return $next($request);
        }

        return response()->json([
            'status' => 403,
            'message' => 'Please verify your email address to continue.',
            'response' => ['code' => 'EMAIL_UNVERIFIED'],
        ], 403);
    }

    private function isWhitelisted(Request $request): bool
    {
        if ($request->is('api/user', 'api/user/') && $request->isMethod('get')) {
            return true;
        }
        if ($request->is('api/email/verification-notification', 'api/email/verification-notification/') && $request->isMethod('post')) {
            return true;
        }
        if ($request->is('api/logout', 'api/logout/') && $request->isMethod('post')) {
            return true;
        }

        return false;
    }
}

<?php

namespace App\Http\Middleware;

use App\Domain\Billing\Contracts\FeaturePricingServiceInterface;
use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Domain\Billing\Models\CreditTransaction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeductCreditsMiddleware
{
    public function __construct(
        protected WalletServiceInterface $walletService,
        protected FeaturePricingServiceInterface $pricingService
    ) {}

    /**
     * @param  \Closure(Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $featureKey = null): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['status' => 401, 'message' => 'Unauthenticated.', 'response' => null], 401);
        }

        $routeName = $request->route()?->getName();
        $billableRoutes = config('billing.billable_routes', []);
        $featureKey = $featureKey ?? ($billableRoutes[$routeName] ?? null);
        if (! $featureKey) {
            \Illuminate\Support\Facades\Log::warning('Credit deduction skipped: route not in billable_routes', [
                'route' => $routeName,
                'billable_routes' => array_keys($billableRoutes),
            ]);
            return $next($request);
        }

        try {
            $cost = $this->pricingService->getCreditCost($featureKey);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 400,
                'message' => $e->getMessage(),
                'response' => null,
            ], 400);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $existing = CreditTransaction::where('idempotency_key', $idempotencyKey)
                ->where('user_id', $user->id)
                ->whereIn('status', [CreditTransaction::STATUS_PENDING, CreditTransaction::STATUS_COMPLETED])
                ->first();
            if ($existing) {
                $request->attributes->set('credit_reservation', [
                    'transaction_id' => $existing->id,
                    'feature_key' => $existing->feature_key,
                    'amount' => (int) abs($existing->amount),
                ]);
                return $next($request);
            }
        }

        try {
            $requestId = $request->attributes->get('request_id') ?? uniqid('req_', true);
            $reservation = $this->walletService->reserveCredits($user, $featureKey, $cost, [
                'reference_type' => 'feature_request',
                'reference_id' => $requestId,
                'metadata' => [
                    'route' => $routeName,
                    'request_id' => $requestId,
                ],
                'idempotency_key' => $idempotencyKey ?: null,
            ]);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'status' => 402,
                'message' => $e->getMessage(),
                'response' => ['credits_balance' => $this->walletService->getBalance($user)],
            ], 402);
        }

        $request->attributes->set('credit_reservation', [
            'transaction_id' => $reservation->id,
            'feature_key' => $featureKey,
            'amount' => $cost,
        ]);

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Contracts\FeaturePricingServiceInterface;
use App\Domain\Billing\Contracts\PaymentServiceInterface;
use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Domain\Billing\Models\CreditTransaction;
use App\Domain\Billing\Models\Feature;
use App\Http\Controllers\Controller;
use App\Services\ApiResponseModifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class BillingController extends Controller
{
    public function __construct(
        protected WalletServiceInterface $walletService,
        protected FeaturePricingServiceInterface $pricingService,
        protected PaymentServiceInterface $paymentService,
        protected ApiResponseModifier $responseModifier,
        protected StripeClient $stripe
    ) {}

    /**
     * Get current user's credit balance.
     */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();
        $balance = $this->walletService->getBalance($user);

        return $this->responseModifier
            ->setData(['credits_balance' => $balance])
            ->setMessage('Balance retrieved successfully')
            ->response();
    }

    /**
     * Get current user's credit activity (transaction history).
     * Query params: per_page (default 20), page, type (purchase|usage|refund|bonus|adjustment).
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min((int) $request->input('per_page', 20), 100);
        $type = $request->input('type');

        $query = CreditTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($type && in_array($type, [
            CreditTransaction::TYPE_PURCHASE,
            CreditTransaction::TYPE_USAGE,
            CreditTransaction::TYPE_REFUND,
            CreditTransaction::TYPE_BONUS,
            CreditTransaction::TYPE_ADJUSTMENT,
        ], true)) {
            $query->where('type', $type);
        }

        $paginator = $query->paginate($perPage);

        $items = $paginator->through(function (CreditTransaction $tx) {
            return [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => $tx->amount,
                'balance_after' => $tx->balance_after,
                'feature_key' => $tx->feature_key,
                'status' => $tx->status ?? 'completed',
                'reference_type' => $tx->reference_type,
                'reference_id' => $tx->reference_id,
                'created_at' => $tx->created_at?->toIso8601String(),
                'metadata' => $tx->metadata,
            ];
        });

        return $this->responseModifier
            ->setData([
                'transactions' => $items->items(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ])
            ->setMessage('Credit activity retrieved successfully')
            ->response();
    }

    /**
     * List billable features with credit costs (for frontend).
     */
    public function features(): JsonResponse
    {
        $features = Feature::where('is_active', true)
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'credit_cost']);

        return $this->responseModifier
            ->setData(['features' => $features])
            ->setMessage('Features retrieved successfully')
            ->response();
    }

    /**
     * Purchase rules (min, increment, cents per credit) for frontend.
     */
    public function purchaseRules(): JsonResponse
    {
        $rules = [
            'min_credits' => config('billing.purchase.min_credits', 100),
            'max_credits' => config('billing.purchase.max_credits', 10000),
            'credit_increment' => config('billing.purchase.credit_increment', 10),
            'cents_per_credit' => config('billing.purchase.cents_per_credit', 5),
        ];

        return $this->responseModifier
            ->setData($rules)
            ->setMessage('Purchase rules retrieved successfully')
            ->response();
    }

    /**
     * Create Stripe Checkout session for buying credits.
     * Body: { "credits": 100 } (min 100, increment by 10).
     */
    public function createCheckout(Request $request): JsonResponse
    {
        $min = config('billing.purchase.min_credits', 100);
        $max = config('billing.purchase.max_credits', 10000);
        $increment = config('billing.purchase.credit_increment', 10);

        $validated = $request->validate([
            'credits' => [
                'required',
                'integer',
                "min:{$min}",
                "max:{$max}",
                function (string $attribute, int $value, \Closure $fail) use ($min, $increment) {
                    if (($value - $min) % $increment !== 0) {
                        $fail("Credits must be in increments of {$increment} (minimum {$min}).");
                    }
                },
            ],
        ]);

        $credits = $validated['credits'];
        $user = $request->user();

        $result = $this->paymentService->createCheckoutSession($user, $credits);

        return $this->responseModifier
            ->setData([
                'checkout_url' => $result['url'],
                'session_id' => $result['session_id'],
            ])
            ->setMessage('Checkout session created')
            ->response();
    }

    /**
     * Confirm a Stripe Checkout session and add credits if not already applied.
     * Call this from the success page with session_id so credits are applied even
     * if the webhook hasn't run yet (e.g. local dev, or webhook delay).
     * Idempotent: safe to call multiple times for the same session.
     */
    public function confirmSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string', 'min:10'],
        ]);
        $sessionId = $validated['session_id'];
        $user = $request->user();

        $existing = CreditTransaction::where('reference_type', 'stripe_checkout_session')
            ->where('reference_id', $sessionId)
            ->where('type', 'purchase')
            ->exists();
        if ($existing) {
            $balance = $this->walletService->getBalance($user);
            return $this->responseModifier
                ->setData(['credits_balance' => $balance, 'already_credited' => true])
                ->setMessage('Session already credited')
                ->response();
        }

        try {
            $session = $this->stripe->checkout->sessions->retrieve($sessionId);
        } catch (\Throwable $e) {
            return $this->responseModifier
                ->setResponseCode(400)
                ->setMessage('Invalid or expired session.')
                ->setData(['credits_balance' => $this->walletService->getBalance($user)])
                ->response();
        }

        if (($session->payment_status ?? '') !== 'paid') {
            return $this->responseModifier
                ->setData(['credits_balance' => $this->walletService->getBalance($user)])
                ->setMessage('Payment not completed yet.')
                ->response();
        }

        $sessionUserId = (string) ($session->metadata->user_id ?? $session->client_reference_id ?? '');
        if ($sessionUserId !== (string) $user->id) {
            return $this->responseModifier
                ->setResponseCode(403)
                ->setMessage('Session does not belong to this account.')
                ->response();
        }

        $credits = (int) ($session->metadata->credits ?? 0);
        if ($credits <= 0) {
            return $this->responseModifier
                ->setResponseCode(400)
                ->setMessage('Invalid session data.')
                ->setData(['credits_balance' => $this->walletService->getBalance($user)])
                ->response();
        }

        $paymentIntentId = $session->payment_intent ?? null;
        $metadata = ['session_id' => $sessionId];
        if ($paymentIntentId !== null) {
            $metadata['payment_intent_id'] = is_string($paymentIntentId) ? $paymentIntentId : $paymentIntentId->id ?? null;
        }

        $this->walletService->addCredits($user, $credits, 'purchase', [
            'reference_type' => 'stripe_checkout_session',
            'reference_id' => $sessionId,
            'metadata' => $metadata,
        ]);

        $balance = $this->walletService->getBalance($user);
        return $this->responseModifier
            ->setData(['credits_balance' => $balance, 'credits_added' => $credits])
            ->setMessage('Credits added successfully')
            ->response();
    }
}

<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\PaymentServiceInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Models\User;
use Stripe\StripeClient;

class PaymentService implements PaymentServiceInterface
{
    public function __construct(
        protected StripeClient $stripe,
        protected string $currency,
        protected int $minCredits,
        protected int $maxCredits,
        protected int $creditIncrement,
        protected float $centsPerCredit
    ) {
        $this->currency = $currency;
        $this->minCredits = $minCredits;
        $this->maxCredits = $maxCredits;
        $this->creditIncrement = $creditIncrement;
        $this->centsPerCredit = $centsPerCredit;
    }

    public function createCheckoutSession(User $user, int $credits): array
    {
        $this->validateCreditsAmount($credits);

        $amountCents = (int) round($credits * $this->centsPerCredit);
        if ($amountCents < 50) {
            throw new BillingException('Minimum charge is 50 cents.', 400, 'INVALID_AMOUNT');
        }

        $frontendUrl = config('app.frontend_url', config('app.url'));
        if (empty($frontendUrl)) {
            throw new BillingException('Billing redirect URL not configured (app.frontend_url).', 500, 'CONFIG_ERROR');
        }
        $successUrl = rtrim($frontendUrl, '/') . '/billing/success?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = rtrim($frontendUrl, '/') . '/billing/cancel';

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $this->currency,
                        'unit_amount' => $amountCents,
                        'product_data' => [
                            'name' => "{$credits} Credits",
                            'description' => "Purchase of {$credits} credits for Luminia SEO.",
                        ],
                    ],
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $user->id,
            'metadata' => [
                'user_id' => (string) $user->id,
                'credits' => (string) $credits,
            ],
            'customer_email' => $user->email,
        ]);

        return [
            'url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    protected function validateCreditsAmount(int $credits): void
    {
        if ($credits < $this->minCredits) {
            throw new BillingException(
                "Minimum purchase is {$this->minCredits} credits.",
                422,
                'INVALID_CREDITS'
            );
        }

        if ($credits > $this->maxCredits) {
            throw new BillingException(
                "Maximum purchase is {$this->maxCredits} credits.",
                422,
                'INVALID_CREDITS'
            );
        }

        $remainder = ($credits - $this->minCredits) % $this->creditIncrement;
        if ($remainder !== 0) {
            throw new BillingException(
                "Credits must be in increments of {$this->creditIncrement} (min {$this->minCredits}).",
                422,
                'INVALID_CREDITS'
            );
        }
    }
}

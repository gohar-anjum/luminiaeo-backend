<?php

namespace App\Domain\Billing\Contracts;

use App\Models\User;

interface PaymentServiceInterface
{
    /**
     * Create a Stripe Checkout session for purchasing credits.
     * Min credits and increment enforced; price = credits * cents_per_credit.
     *
     * @return array{url: string, session_id: string}
     */
    public function createCheckoutSession(User $user, int $credits): array;
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credit purchase rules (one-time payments only)
    |--------------------------------------------------------------------------
    | Min 100 credits, then increments of 10 (110, 120, 130...). Max caps API abuse.
    | 1 credit = 5 cents.
    */
    'purchase' => [
        'min_credits' => (int) env('BILLING_MIN_CREDITS', 100),
        'max_credits' => (int) env('BILLING_MAX_CREDITS', 5000),
        'credit_increment' => (int) env('BILLING_CREDIT_INCREMENT', 10),
        'cents_per_credit' => (float) env('BILLING_CENTS_PER_CREDIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signup bonus
    |--------------------------------------------------------------------------
    */
    'signup_bonus_credits' => (int) env('BILLING_SIGNUP_BONUS_CREDITS', 10),

    /*
    |--------------------------------------------------------------------------
    | Stripe (one-time checkout only; keys from env)
    |--------------------------------------------------------------------------
    | STRIPE_WEBHOOK_SECRET is required for webhook verification. If missing,
    | POST /api/billing/webhook returns 503. Set app.frontend_url for redirects.
    */
    'stripe' => [
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => strtolower(env('BILLING_CURRENCY', env('CASHIER_CURRENCY', 'usd'))),
    ],

];

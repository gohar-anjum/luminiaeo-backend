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
    | Absolute max credits per single admin adjustment (add or remove).
    */
    'admin_adjust_max_credits' => (int) env('BILLING_ADMIN_ADJUST_MAX_CREDITS', 1_000_000),

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

    /*
    |--------------------------------------------------------------------------
    | Billable routes (route name => feature key)
    |--------------------------------------------------------------------------
    | Used by credit.deduct middleware to reserve credits at request time.
    | Refund (reverse) on failure; complete reservation on success.
    */
    'billable_routes' => [
        'citations.analyze' => 'citation_feature',
        'keyword-planner.ideas' => 'keyword_ideas',
        'keyword-planner.informational-ideas' => 'keyword_ideas',
        'keyword-planner.for-site' => 'keyword_ideas',
        'keyword-planner.combined-clusters' => 'keyword_ideas',
        'keyword-research.create' => 'keyword_ideas',
        'seo.backlinks.submit' => 'backlink_feature',
        'faq.generate' => 'faq_generator',
        'faq.task.create' => 'faq_generator',
        'page-analysis.meta-optimize' => 'meta_tag_optimizer',
        'page-analysis.semantic-score' => 'semantic_score_checker',
        'page-analysis.content-outline' => 'semantic_content_generator',
    ],

];

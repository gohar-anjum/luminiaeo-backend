<?php

namespace App\Providers;

use App\Domain\Billing\Contracts\FeaturePricingServiceInterface;
use App\Domain\Billing\Contracts\PaymentServiceInterface;
use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Domain\Billing\Contracts\WebhookServiceInterface;
use App\Domain\Billing\Services\CreditConsumptionService;
use App\Domain\Billing\Services\FeaturePricingService;
use App\Domain\Billing\Services\PaymentService;
use App\Domain\Billing\Services\WalletService;
use App\Domain\Billing\Services\WebhookService;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, function () {
            $secret = config('cashier.secret') ?? config('services.stripe.secret') ?? env('STRIPE_SECRET');
            return new StripeClient($secret);
        });

        $this->app->singleton(WalletServiceInterface::class, WalletService::class);
        $this->app->singleton(FeaturePricingServiceInterface::class, FeaturePricingService::class);

        $this->app->singleton(PaymentServiceInterface::class, function () {
            $config = config('billing.purchase', []);
            return new PaymentService(
                app(StripeClient::class),
                config('billing.stripe.currency', 'usd'),
                $config['min_credits'] ?? 100,
                $config['max_credits'] ?? 10000,
                $config['credit_increment'] ?? 10,
                $config['cents_per_credit'] ?? 5
            );
        });

        $this->app->singleton(WebhookServiceInterface::class, function () {
            return new WebhookService(
                config('billing.stripe.webhook_secret', ''),
                app(WalletServiceInterface::class)
            );
        });

        $this->app->singleton(CreditConsumptionService::class, CreditConsumptionService::class);
    }
}

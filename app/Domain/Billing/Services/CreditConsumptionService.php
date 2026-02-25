<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\FeaturePricingServiceInterface;
use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Models\User;

/**
 * Convenience service for feature consumption: assert balance before running,
 * deduct after success. Use assertCanConsume() at entry, recordUsage() after success.
 */
class CreditConsumptionService
{
    public function __construct(
        protected WalletServiceInterface $walletService,
        protected FeaturePricingServiceInterface $pricingService
    ) {}

    /**
     * Call before running the feature. Throws InsufficientCreditsException or FeatureNotActiveException.
     */
    public function assertCanConsume(User $user, string $featureKey): int
    {
        $this->pricingService->validateFeatureForUsage($featureKey);
        $cost = $this->pricingService->getCreditCost($featureKey);
        $this->walletService->assertSufficientCredits($user, $cost);

        return $cost;
    }

    /**
     * Call after the feature has completed successfully. Deducts credits and writes ledger.
     */
    public function recordUsage(User $user, string $featureKey, ?int $cost = null): void
    {
        $cost = $cost ?? $this->pricingService->getCreditCost($featureKey);
        $this->walletService->deductCredits($user, $cost, 'usage', [
            'feature_key' => $featureKey,
            'metadata' => ['feature_key' => $featureKey],
        ]);
    }
}

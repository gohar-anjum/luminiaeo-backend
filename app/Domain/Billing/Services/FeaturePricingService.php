<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\FeaturePricingServiceInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Exceptions\FeatureNotActiveException;
use App\Domain\Billing\Models\Feature;

class FeaturePricingService implements FeaturePricingServiceInterface
{
    public function getCreditCost(string $featureKey): int
    {
        $feature = Feature::where('key', $featureKey)->first();

        if (! $feature) {
            throw new BillingException("Unknown feature: {$featureKey}", 400, 'UNKNOWN_FEATURE');
        }

        if (! $feature->is_active) {
            throw new FeatureNotActiveException("Feature is not active: {$featureKey}");
        }

        return $feature->credit_cost;
    }

    public function isFeatureActive(string $featureKey): bool
    {
        return Feature::where('key', $featureKey)->where('is_active', true)->exists();
    }

    public function validateFeatureForUsage(string $featureKey): void
    {
        $this->getCreditCost($featureKey);
    }
}

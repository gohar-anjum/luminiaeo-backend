<?php

namespace App\Domain\Billing\Contracts;

interface FeaturePricingServiceInterface
{
    public function getCreditCost(string $featureKey): int;

    public function isFeatureActive(string $featureKey): bool;

    public function validateFeatureForUsage(string $featureKey): void;
}

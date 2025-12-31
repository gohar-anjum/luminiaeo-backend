<?php

namespace App\Services;

use App\Models\LocationCode;
use Illuminate\Support\Facades\Cache;

class LocationCodeService
{
    /**
     * Get location code by code with caching
     * 
     * @param int $locationCode
     * @return LocationCode|null
     */
    public function getLocationCode(int $locationCode): ?LocationCode
    {
        $cacheKey = "location_code:service:{$locationCode}";
        $cacheTTL = config('cache.location_codes_ttl', 86400);

        return Cache::remember($cacheKey, $cacheTTL, function () use ($locationCode) {
            return LocationCode::where('location_code', $locationCode)->first();
        });
    }

    /**
     * Validate if location code exists
     * 
     * @param int $locationCode
     * @return bool
     */
    public function isValid(int $locationCode): bool
    {
        return $this->getLocationCode($locationCode) !== null;
    }

    /**
     * Get country ISO code from location code
     * 
     * @param int $locationCode
     * @return string|null Returns lowercase ISO code (e.g., 'us', 'uk') or null if not found
     */
    public function getCountryIsoCode(int $locationCode): ?string
    {
        $location = $this->getLocationCode($locationCode);
        
        if (!$location || !$location->country_iso_code) {
            return null;
        }

        return strtolower($location->country_iso_code);
    }

    /**
     * Get location code from country ISO code
     * 
     * @param string $countryIsoCode Country ISO code (e.g., 'us', 'uk', 'US', 'GB')
     * @return int|null Returns location code or null if not found
     */
    public function getLocationCodeFromIso(string $countryIsoCode): ?int
    {
        $isoCode = strtoupper($countryIsoCode);
        
        $cacheKey = "location_code:iso:{$isoCode}";
        $cacheTTL = config('cache.location_codes_ttl', 86400);

        return Cache::remember($cacheKey, $cacheTTL, function () use ($isoCode) {
            $location = LocationCode::where('country_iso_code', $isoCode)
                ->where('location_type', 'Country')
                ->first();
            
            return $location ? $location->location_code : null;
        });
    }

    /**
     * Map location code to region (lowercase ISO code)
     * Falls back to database lookup if not in hardcoded mapping
     * 
     * @param int $locationCode
     * @param string $default Default region if not found (default: 'us')
     * @return string
     */
    public function mapLocationCodeToRegion(int $locationCode, string $default = 'us'): string
    {
        $isoCode = $this->getCountryIsoCode($locationCode);
        
        return $isoCode ?? $default;
    }

    /**
     * Map region (ISO code) to location code
     * Falls back to database lookup if not in hardcoded mapping
     * 
     * @param string $region Region code (e.g., 'us', 'uk')
     * @param int $default Default location code if not found (default: 2840 for US)
     * @return int
     */
    public function mapRegionToLocationCode(string $region, int $default = 2840): int
    {
        $locationCode = $this->getLocationCodeFromIso($region);
        
        return $locationCode ?? $default;
    }

    /**
     * Get default location code (US)
     * 
     * @return int
     */
    public function getDefaultLocationCode(): int
    {
        return $this->getLocationCodeFromIso('us') ?? 2840;
    }

    /**
     * Get all countries with caching
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllCountries()
    {
        $cacheKey = 'location_code:service:countries';
        $cacheTTL = config('cache.location_codes_ttl', 86400);

        return Cache::remember($cacheKey, $cacheTTL, function () {
            return LocationCode::countries()
                ->orderBy('location_name')
                ->get();
        });
    }
}


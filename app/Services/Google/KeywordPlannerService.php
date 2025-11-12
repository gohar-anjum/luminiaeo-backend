<?php

namespace App\Services\Google;

use App\Helpers\GoogleClientHelper;
use Google\Ads\GoogleAds\V19\Enums\KeywordPlanNetworkEnum\KeywordPlanNetwork;
use Google\Ads\GoogleAds\V19\Services\GenerateKeywordIdeasRequest;
use Google\Ads\GoogleAds\V19\Services\KeywordSeed;

class KeywordPlannerService
{
    /**
     * Fetch keyword ideas from Google Keyword Planner API (v19)
     *
     * @param string $seedKeyword
     * @param string $languageId
     * @param string $geoTargetId
     * @return array
     */
    public function getKeywordIdeas(
        string $seedKeyword,
        string $languageId = '1000', // English
        string $geoTargetId = '2840' // USA
    ): array
    {
        $client = GoogleClientHelper::getGoogleAdsClient();
        $service = $client->getKeywordPlanIdeaServiceClient();

        // Step 1: Define the keyword seed
        $keywordSeed = new KeywordSeed([
            'keywords' => [$seedKeyword],
        ]);

        // Step 2: Build a valid GenerateKeywordIdeasRequest object
        $request = new GenerateKeywordIdeasRequest([
            'customer_id' => config('services.google.login_customer_id'),
            'language' => 'languageConstants/' . $languageId,
            'geo_target_constants' => ['geoTargetConstants/' . $geoTargetId],
            'keyword_plan_network' => KeywordPlanNetwork::GOOGLE_SEARCH,
            'keyword_seed' => $keywordSeed,
        ]);

        // Step 3: Call the API using the request object
        $response = $service->generateKeywordIdeas($request);

        // Step 4: Map results into a simplified array
        $ideas = [];
        foreach ($response->iterateAllElements() as $result) {
            $metrics = $result->getKeywordIdeaMetrics();

            $ideas[] = [
                'text' => $result->getText(),
                'avg_monthly_searches' => $metrics?->getAvgMonthlySearches(),
                'competition' => $metrics?->getCompetition(),
                'low_bid' => $metrics && $metrics->getLowTopOfPageBidMicros()
                    ? $metrics->getLowTopOfPageBidMicros() / 1_000_000 : null,
                'high_bid' => $metrics && $metrics->getHighTopOfPageBidMicros()
                    ? $metrics->getHighTopOfPageBidMicros() / 1_000_000 : null,
            ];
        }

        return $ideas;
    }
}

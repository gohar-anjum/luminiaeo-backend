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
        string $languageId = '1000',   // English
        string $geoTargetId = '2840'   // USA
    ): array
    {
        $client = GoogleClientHelper::getGoogleAdsClient();
        $keywordPlanIdeaServiceClient = $client->getKeywordPlanIdeaServiceClient();

        $keywordSeed = new KeywordSeed([
            'keywords' => [$seedKeyword],
        ]);

        $request = new GenerateKeywordIdeasRequest([
            'customerId' => config('services.google.login_customer_id'),
            'language' => 'languageConstants/' . $languageId,
            'geoTargetConstants' => ['geoTargetConstants/' . $geoTargetId],
            'keywordPlanNetwork' => KeywordPlanNetwork::GOOGLE_SEARCH,
            'keywordSeed' => $keywordSeed,
        ]);

        $response = $keywordPlanIdeaServiceClient->generateKeywordIdeas($request);

        $ideas = [];
        foreach ($response->getResults() as $result) {
            $metrics = $result->getKeywordIdeaMetrics();
            $ideas[] = [
                'text' => $result->getText(),
                'avg_monthly_searches' => $metrics ? $metrics->getAvgMonthlySearches() : null,
                'competition' => $metrics ? $metrics->getCompetition() : null,
                'low_bid' => $metrics ? $metrics->getLowTopOfPageBidMicros() / 1_000_000 : null,
                'high_bid' => $metrics ? $metrics->getHighTopOfPageBidMicros() / 1_000_000 : null,
            ];
        }

        return $ideas;
    }
}

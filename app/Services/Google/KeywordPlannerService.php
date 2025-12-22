<?php

namespace App\Services\Google;

use App\DTOs\KeywordDataDTO;
use App\Helpers\GoogleClientHelper;
use Google\Ads\GoogleAds\V19\Enums\KeywordPlanNetworkEnum\KeywordPlanNetwork;
use Google\Ads\GoogleAds\V19\Services\GenerateKeywordIdeasRequest;
use Google\Ads\GoogleAds\V19\Services\KeywordSeed;
use Illuminate\Support\Facades\Log;

class KeywordPlannerService
{
    public function getKeywordIdeas(
        string $seedKeyword,
        string $languageId = '1000',
        string $geoTargetId = '2840',
        ?int $maxResults = null
    ): array {
        try {
            $client = GoogleClientHelper::getGoogleAdsClient();
            $service = $client->getKeywordPlanIdeaServiceClient();

            $keywordSeed = new KeywordSeed([
                'keywords' => [$seedKeyword],
            ]);

            $request = new GenerateKeywordIdeasRequest([
                'customer_id' => config('services.google.login_customer_id'),
                'language' => 'languageConstants/' . $languageId,
                'geo_target_constants' => ['geoTargetConstants/' . $geoTargetId],
                'keyword_plan_network' => KeywordPlanNetwork::GOOGLE_SEARCH,
                'keyword_seed' => $keywordSeed,
            ]);

            $response = $service->generateKeywordIdeas($request);

            $ideas = [];
            $count = 0;

            foreach ($response->iterateAllElements() as $result) {
                if ($maxResults && $count >= $maxResults) {
                    break;
                }

                $metrics = $result->getKeywordIdeaMetrics();
                $keywordText = $result->getText();

                $competitionValue = null;
                if ($metrics && $metrics->getCompetition()) {
                    $competitionEnum = $metrics->getCompetition();
                    $competitionValue = match ($competitionEnum) {
                        1 => 0.0,
                        2 => 0.5,
                        3 => 1.0,
                        default => null,
                    };
                }

                $ideas[] = new KeywordDataDTO(
                    keyword: $keywordText,
                    source: 'google_planner',
                    searchVolume: $metrics?->getAvgMonthlySearches(),
                    competition: $competitionValue,
                    cpc: $metrics && $metrics->getLowTopOfPageBidMicros()
                        ? ($metrics->getLowTopOfPageBidMicros() + ($metrics->getHighTopOfPageBidMicros() ?? $metrics->getLowTopOfPageBidMicros())) / 2_000_000
                        : null,
                );

                $count++;
            }

            Log::info('Google Keyword Planner API success', [
                'seed_keyword' => $seedKeyword,
                'keywords_found' => count($ideas),
            ]);

            return $ideas;
        } catch (\Throwable $e) {
            Log::error('Google Keyword Planner API error', [
                'seed_keyword' => $seedKeyword,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }
}

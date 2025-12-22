<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Google\KeywordPlannerService;
use App\Services\DataForSEO\DataForSEOService;
use App\Services\Keyword\CombinedKeywordService;
use App\Services\Keyword\SemanticClusteringService;
use App\Services\ApiResponseModifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KeywordPlannerController extends Controller
{
    private KeywordPlannerService $keywordPlannerService;
    private DataForSEOService $dataForSEOService;
    private CombinedKeywordService $combinedKeywordService;
    private SemanticClusteringService $clusteringService;
    private ApiResponseModifier $responseModifier;

    public function __construct(
        KeywordPlannerService $keywordPlannerService,
        DataForSEOService $dataForSEOService,
        CombinedKeywordService $combinedKeywordService,
        SemanticClusteringService $clusteringService,
        ApiResponseModifier $responseModifier
    ) {
        $this->keywordPlannerService = $keywordPlannerService;
        $this->dataForSEOService = $dataForSEOService;
        $this->combinedKeywordService = $combinedKeywordService;
        $this->clusteringService = $clusteringService;
        $this->responseModifier = $responseModifier;
    }

    public function getKeywordIdeas(Request $request)
    {
        $request->validate(['keyword' => 'required|string']);
        $ideas = $this->keywordPlannerService->getKeywordIdeas($request->keyword);

        return response()->json([
            'status' => 'success',
            'count' => count($ideas),
            'data' => $ideas,
        ]);
    }

    public function getKeywordsForSite(Request $request)
    {
        $request->validate([
            'target' => 'required|string|max:255',
            'location_code' => 'sometimes|integer|min:1',
            'language_code' => 'sometimes|string|size:2',
            'search_partners' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:1000',
        ]);

        try {
            $keywords = $this->dataForSEOService->getKeywordsForSite(
                $request->input('target'),
                $request->input('location_code', 2840),
                $request->input('language_code', 'en'),
                $request->input('search_partners', true),
                null,
                null,
                false,
                null,
                $request->input('limit')
            );

            $data = array_map(function ($dto) {
                return $dto->toArray();
            }, $keywords);

            Log::info('Keywords for site retrieved', [
                'target' => $request->input('target'),
                'count' => count($data),
            ]);

            return $this->responseModifier
                ->setData($data)
                ->setMessage('Keywords for site retrieved successfully')
                ->response();
        } catch (\Exception $e) {
            Log::error('Error getting keywords for site', [
                'error' => $e->getMessage(),
                'target' => $request->input('target'),
            ]);

            return $this->responseModifier
                ->setMessage('Failed to retrieve keywords: ' . $e->getMessage())
                ->setResponseCode(500)
                ->response();
        }
    }

    public function getCombinedKeywordsWithClusters(Request $request)
    {
        $request->validate([
            'target' => 'required|string|max:255',
            'location_code' => 'sometimes|integer|min:1',
            'language_code' => 'sometimes|string|size:2',
            'limit' => 'sometimes|integer|min:1|max:1000',
            'num_clusters' => 'sometimes|integer|min:2|max:50',
            'enable_clustering' => 'sometimes|boolean',
        ]);

        try {
            $keywords = $this->combinedKeywordService->getCombinedKeywords(
                $request->input('target'),
                $request->input('location_code', 2840),
                $request->input('language_code', 'en'),
                $request->input('limit')
            );

            $data = [
                'keywords' => array_map(function ($dto) {
                    return $dto->toArray();
                }, $keywords),
                'total_count' => count($keywords),
            ];

            if ($request->input('enable_clustering', true)) {
                $numClusters = $request->input('num_clusters');
                if ($numClusters === null) {
                    $numClusters = min(10, max(3, (int) (count($keywords) / 20)));
                }

                $clusteringResult = $this->clusteringService->clusterKeywords($keywords, $numClusters, true);

                $data['clusters'] = array_map(function ($cluster) {
                    return [
                        'topic_name' => $cluster->topicName,
                        'keyword_count' => $cluster->keywordCount,
                        'suggested_article_titles' => $cluster->suggestedArticleTitles,
                        'recommended_faq_questions' => $cluster->recommendedFaqQuestions,
                    ];
                }, $clusteringResult['clusters']);

                $data['keyword_cluster_map'] = $clusteringResult['keyword_cluster_map'];
                $data['clusters_count'] = count($clusteringResult['clusters']);
            }

            Log::info('Combined keywords with clusters retrieved', [
                'target' => $request->input('target'),
                'keywords_count' => count($keywords),
                'clusters_count' => $data['clusters_count'] ?? 0,
            ]);

            return $this->responseModifier
                ->setData($data)
                ->setMessage('Combined keywords with clusters retrieved successfully')
                ->response();
        } catch (\Exception $e) {
            Log::error('Error getting combined keywords with clusters', [
                'error' => $e->getMessage(),
                'target' => $request->input('target'),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->responseModifier
                ->setMessage('Failed to retrieve combined keywords: ' . $e->getMessage())
                ->setResponseCode(500)
                ->response();
        }
    }
}

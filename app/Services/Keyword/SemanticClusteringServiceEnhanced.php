<?php

namespace App\Services\Keyword;

use App\DTOs\ClusterDataDTO;
use App\DTOs\KeywordDataDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SemanticClusteringServiceEnhanced
{
    protected ?string $clusteringServiceUrl;

    public function __construct()
    {
        $this->clusteringServiceUrl = config('services.keyword_clustering.url');
    }

    public function clusterKeywords(array $keywords, int $numClusters = 5): array
    {
        if (empty($keywords)) {
            return ['clusters' => [], 'keyword_cluster_map' => []];
        }

        if ($this->clusteringServiceUrl) {
            return $this->clusterViaPythonService($keywords, $numClusters);
        }

        return $this->simpleClustering($keywords, $numClusters);
    }

    protected function clusterViaPythonService(array $keywords, int $numClusters): array
    {
        try {
            $keywordTexts = array_map(fn($k) => $k->keyword, $keywords);

            $keywordMetadata = $this->buildKeywordMetadata($keywords);

            $response = Http::timeout(120)
                ->post("{$this->clusteringServiceUrl}/cluster", [
                    'keywords' => $keywordTexts,
                    'num_clusters' => $numClusters,
                    'include_metadata' => true,
                    'keyword_metadata' => $keywordMetadata,
                ]);

            if (!$response->successful()) {
                Log::warning('Enhanced clustering service failed, using fallback', [
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);
                return $this->simpleClustering($keywords, $numClusters);
            }

            $data = $response->json();

            if (isset($data['clusters']) && is_array($data['clusters'])) {
                return $this->processEnhancedResponse($data, $keywords);
            }

            return $this->processLegacyResponse($data, $keywords);

        } catch (\Throwable $e) {
            Log::error('Enhanced clustering service error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->simpleClustering($keywords, $numClusters);
        }
    }

    protected function processEnhancedResponse(array $data, array $keywords): array
    {
        $clusterMap = $data['cluster_map'] ?? [];
        $clustersData = $data['clusters'] ?? [];

        $clusterDTOs = [];
        $keywordClusterMap = [];

        foreach ($clusterMap as $keyword => $clusterId) {
            $keywordClusterMap[$keyword] = $clusterId;
        }

        foreach ($clustersData as $index => $clusterData) {
            $clusterDTO = new ClusterDataDTO(
                topicName: $clusterData['topic_name'] ?? "Cluster " . ($index + 1),
                description: $clusterData['description'] ?? null,
                suggestedArticleTitles: $clusterData['suggested_article_titles'] ?? [],
                recommendedFaqQuestions: $clusterData['recommended_faq_questions'] ?? [],
                schemaSuggestions: $clusterData['schema_suggestions'] ?? [],
                aiVisibilityProjection: $clusterData['ai_visibility_projection'] ?? null,
                keywordCount: $clusterData['keyword_count'] ?? 0,
            );

            $clusterDTOs[] = $clusterDTO;
        }

        Log::info('Processed enhanced clustering response', [
            'clusters_count' => count($clusterDTOs),
            'keywords_count' => count($keywords),
        ]);

        return [
            'clusters' => $clusterDTOs,
            'keyword_cluster_map' => $keywordClusterMap,
        ];
    }

    protected function processLegacyResponse(array $data, array $keywords): array
    {
        $clusterMap = $data['cluster_map'] ?? [];
        $clusterLabels = $data['cluster_labels'] ?? [];

        $clusters = [];
        $keywordClusterMap = [];

        foreach ($clusterMap as $keyword => $clusterId) {
            $keywordClusterMap[$keyword] = $clusterId;

            if (!isset($clusters[$clusterId])) {
                $clusters[$clusterId] = [
                    'keywords' => [],
                    'label' => $clusterLabels[$clusterId] ?? "Cluster " . ($clusterId + 1),
                ];
            }

            $clusters[$clusterId]['keywords'][] = $keyword;
        }

        $clusterDTOs = [];
        foreach ($clusters as $clusterId => $clusterData) {
            $keywordsInCluster = array_filter($keywords, fn($k) => in_array($k->keyword, $clusterData['keywords']));

            $clusterDTOs[] = new ClusterDataDTO(
                topicName: $clusterData['label'],
                keywordCount: count($clusterData['keywords']),
                suggestedArticleTitles: $this->generateArticleTitles($clusterData['keywords']),
                recommendedFaqQuestions: $this->extractQuestions($keywordsInCluster),
            );
        }

        return [
            'clusters' => $clusterDTOs,
            'keyword_cluster_map' => $keywordClusterMap,
        ];
    }

    protected function buildKeywordMetadata(array $keywords): array
    {
        $metadata = [];

        foreach ($keywords as $keywordDTO) {
            $keywordMetadata = [];

            if ($keywordDTO->questionVariations) {
                $keywordMetadata['question_variations'] = $keywordDTO->questionVariations;
            }

            if ($keywordDTO->searchVolume !== null) {
                $keywordMetadata['search_volume'] = $keywordDTO->searchVolume;
            }

            if ($keywordDTO->competition !== null) {
                $keywordMetadata['competition'] = $keywordDTO->competition;
            }

            if ($keywordDTO->cpc !== null) {
                $keywordMetadata['cpc'] = $keywordDTO->cpc;
            }

            if (!empty($keywordMetadata)) {
                $metadata[$keywordDTO->keyword] = $keywordMetadata;
            }
        }

        return $metadata;
    }

    protected function simpleClustering(array $keywords, int $numClusters): array
    {
        $groups = [];
        foreach ($keywords as $keyword) {
            $firstWord = explode(' ', $keyword->keyword)[0] ?? $keyword->keyword;
            $groupKey = substr(strtolower($firstWord), 0, 3);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            $groups[$groupKey][] = $keyword;
        }

        $sortedGroups = collect($groups)->sortByDesc(fn($group) => count($group))->take($numClusters);

        $clusters = [];
        $keywordClusterMap = [];
        $clusterId = 0;

        foreach ($sortedGroups as $groupKey => $groupKeywords) {
            $keywordTexts = array_map(fn($k) => $k->keyword, $groupKeywords);

            foreach ($groupKeywords as $keyword) {
                $keywordClusterMap[$keyword->keyword] = $clusterId;
            }

            $clusters[] = new ClusterDataDTO(
                topicName: ucfirst($groupKey) . " Related",
                keywordCount: count($groupKeywords),
                suggestedArticleTitles: $this->generateArticleTitles($keywordTexts),
                recommendedFaqQuestions: $this->extractQuestions($groupKeywords),
            );

            $clusterId++;
        }

        return [
            'clusters' => $clusters,
            'keyword_cluster_map' => $keywordClusterMap,
        ];
    }

    protected function generateArticleTitles(array $keywords): array
    {
        if (empty($keywords)) {
            return [];
        }

        $words = [];
        foreach ($keywords as $keyword) {
            $keywordWords = explode(' ', strtolower($keyword));
            foreach ($keywordWords as $word) {
                if (strlen($word) > 3) {
                    $words[] = $word;
                }
            }
        }

        $wordCounts = array_count_values($words);
        arsort($wordCounts);
        $topWords = array_slice(array_keys($wordCounts), 0, 3);

        $titles = [];
        if (!empty($topWords)) {
            $base = implode(' ', $topWords);
            $titles[] = "Complete Guide to {$base}";
            $titles[] = "Everything You Need to Know About {$base}";
            $titles[] = "{$base}: Best Practices and Tips";
        }

        return array_slice($titles, 0, 5);
    }

    protected function extractQuestions(array $keywords): array
    {
        $questions = [];

        foreach ($keywords as $keyword) {
            if ($keyword->questionVariations) {
                $questions = array_merge($questions, $keyword->questionVariations);
            } elseif (preg_match('/^(what|how|why|when|where|who|can|should|is|are|do|does)/i', $keyword->keyword)) {
                $questions[] = $keyword->keyword;
            }
        }

        return array_unique(array_slice($questions, 0, 10));
    }
}

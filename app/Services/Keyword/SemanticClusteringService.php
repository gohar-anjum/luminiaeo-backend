<?php

namespace App\Services\Keyword;

use App\DTOs\ClusterDataDTO;
use App\DTOs\KeywordDataDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SemanticClusteringService
{
    protected ?string $clusteringServiceUrl;

    public function __construct()
    {
        $this->clusteringServiceUrl = config('services.keyword_clustering.url');
    }

    public function clusterKeywords(array $keywords, int $numClusters = 5, bool $useRuleBased = true): array
    {
        if (empty($keywords)) {
            return ['clusters' => [], 'keyword_cluster_map' => []];
        }

        if ($this->clusteringServiceUrl) {
            return $this->clusterViaPythonService($keywords, $numClusters, $useRuleBased);
        }

        return $this->simpleClustering($keywords, $numClusters);
    }

    protected function clusterViaPythonService(array $keywords, int $numClusters, bool $useRuleBased = true): array
    {
        try {
            $keywordTexts = array_map(fn($k) => $k->keyword, $keywords);

            $endpoint = $useRuleBased ? '/cluster-rule-based' : '/cluster';
            $payload = [
                'keywords' => $keywordTexts,
                'num_clusters' => $numClusters,
            ];

            if (!$useRuleBased) {
                $payload['use_ml'] = true;
            } else {
                $payload['similarity_threshold'] = 0.3;
            }

            $response = Http::timeout(120)
                ->post("{$this->clusteringServiceUrl}{$endpoint}", $payload);

            if (!$response->successful()) {
                Log::warning('Clustering service failed, using fallback', [
                    'status' => $response->status(),
                ]);
                return $this->simpleClustering($keywords, $numClusters);
            }

            $data = $response->json();
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
        } catch (\Throwable $e) {
            Log::error('Clustering service error', [
                'error' => $e->getMessage(),
            ]);
            return $this->simpleClustering($keywords, $numClusters);
        }
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
            if (preg_match('/^(what|how|why|when|where|who|can|should|is|are|do|does)/i', $keyword->keyword)) {
                $questions[] = $keyword->keyword;
            }
        }

        return array_unique(array_slice($questions, 0, 10));
    }
}

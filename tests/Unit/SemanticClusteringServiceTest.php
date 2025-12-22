<?php

namespace Tests\Unit;

use App\DTOs\ClusterDataDTO;
use App\DTOs\KeywordDataDTO;
use App\Services\Keyword\SemanticClusteringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemanticClusteringServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SemanticClusteringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.keyword_clustering.url' => 'http://clustering-service:8000',
        ]);

        $this->service = new SemanticClusteringService();
    }

    public function test_cluster_keywords_returns_empty_for_empty_input(): void
    {
        $result = $this->service->clusterKeywords([]);
        $this->assertEquals(['clusters' => [], 'keyword_cluster_map' => []], $result);
    }

    public function test_cluster_keywords_uses_python_service_when_available(): void
    {
        Http::fake([
            'clustering-service:8000/cluster-rule-based' => Http::response([
                'cluster_map' => [
                    'seo tools' => 0,
                    'best seo tools' => 0,
                    'seo software' => 1,
                ],
                'cluster_labels' => ['Seo Tools', 'Seo Software'],
                'num_clusters' => 2,
                'cluster_sizes' => [0 => 2, 1 => 1],
            ], 200),
        ]);

        $keywords = [
            new KeywordDataDTO(keyword: 'seo tools', source: 'test', searchVolume: 1000, competition: 0.5, cpc: 1.5),
            new KeywordDataDTO(keyword: 'best seo tools', source: 'test', searchVolume: 800, competition: 0.6, cpc: 2.0),
            new KeywordDataDTO(keyword: 'seo software', source: 'test', searchVolume: 600, competition: 0.4, cpc: 1.0),
        ];

        $result = $this->service->clusterKeywords($keywords, 2, true);

        $this->assertArrayHasKey('clusters', $result);
        $this->assertArrayHasKey('keyword_cluster_map', $result);
        $this->assertCount(2, $result['clusters']);
    }

    public function test_cluster_keywords_falls_back_to_simple_clustering(): void
    {
        Http::fake([
            'clustering-service:8000/*' => Http::response([], 500),
        ]);

        $keywords = [
            new KeywordDataDTO(keyword: 'seo tools', source: 'test', searchVolume: 1000, competition: 0.5, cpc: 1.5),
            new KeywordDataDTO(keyword: 'seo software', source: 'test', searchVolume: 800, competition: 0.6, cpc: 2.0),
        ];

        $result = $this->service->clusterKeywords($keywords, 2);

        $this->assertArrayHasKey('clusters', $result);
        $this->assertArrayHasKey('keyword_cluster_map', $result);
    }

    public function test_cluster_keywords_uses_simple_clustering_when_service_unavailable(): void
    {
        config(['services.keyword_clustering.url' => null]);
        $service = new SemanticClusteringService();

        $keywords = [
            new KeywordDataDTO(keyword: 'seo tools', source: 'test', searchVolume: 1000, competition: 0.5, cpc: 1.5),
        ];

        $result = $service->clusterKeywords($keywords, 1);

        $this->assertArrayHasKey('clusters', $result);
    }
}


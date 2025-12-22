<?php

namespace Tests\Unit;

use App\Models\Keyword;
use App\Models\KeywordResearchJob;
use App\Repositories\KeywordRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected KeywordRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new KeywordRepository();
    }

    public function test_create_keyword(): void
    {
        $job = KeywordResearchJob::factory()->create();

        $keyword = $this->repository->create([
            'keyword_research_job_id' => $job->id,
            'keyword' => 'test keyword',
            'source' => 'test',
            'search_volume' => 1000,
            'competition' => 0.5,
            'cpc' => 1.5,
        ]);

        $this->assertInstanceOf(Keyword::class, $keyword);
        $this->assertEquals('test keyword', $keyword->keyword);
        $this->assertEquals($job->id, $keyword->keyword_research_job_id);
    }

    public function test_find_by_job_id(): void
    {
        $job = KeywordResearchJob::factory()->create();
        Keyword::factory()->count(3)->create(['keyword_research_job_id' => $job->id]);

        $keywords = $this->repository->findByJobId($job->id);

        $this->assertCount(3, $keywords);
    }

    public function test_find_by_cluster_id(): void
    {
        $cluster = \App\Models\KeywordCluster::factory()->create();
        Keyword::factory()->count(2)->create(['keyword_cluster_id' => $cluster->id]);

        $keywords = $this->repository->findByClusterId($cluster->id);

        $this->assertCount(2, $keywords);
    }

    public function test_update_keyword(): void
    {
        $keyword = Keyword::factory()->create(['search_volume' => 100]);

        $updated = $this->repository->update($keyword, ['search_volume' => 200]);

        $this->assertEquals(200, $updated->search_volume);
    }

    public function test_delete_keyword(): void
    {
        $keyword = Keyword::factory()->create();

        $this->repository->delete($keyword);

        $this->assertDatabaseMissing('keywords', ['id' => $keyword->id]);
    }
}


<?php

namespace Tests\Unit;

use App\Models\KeywordCache;
use App\Repositories\KeywordCacheRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordCacheRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected KeywordCacheRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new KeywordCacheRepository();
    }

    public function test_find_valid_returns_valid_cache(): void
    {
        KeywordCache::factory()->create([
            'keyword' => 'test',
            'language_code' => 'en',
            'location_code' => 2840,
            'expires_at' => Carbon::now()->addDays(1),
        ]);

        $cache = $this->repository->findValid('test', 'en', 2840);

        $this->assertInstanceOf(KeywordCache::class, $cache);
        $this->assertEquals('test', $cache->keyword);
    }

    public function test_find_valid_returns_null_for_expired(): void
    {
        KeywordCache::factory()->create([
            'keyword' => 'expired',
            'language_code' => 'en',
            'location_code' => 2840,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $cache = $this->repository->findValid('expired', 'en', 2840);

        $this->assertNull($cache);
    }

    public function test_create_sets_default_expiration(): void
    {
        $cache = $this->repository->create([
            'keyword' => 'new keyword',
            'language_code' => 'en',
            'location_code' => 2840,
            'search_volume' => 1000,
        ]);

        $this->assertNotNull($cache->expires_at);
        $this->assertTrue($cache->expires_at->isFuture());
    }

    public function test_bulk_update_creates_and_updates(): void
    {
        KeywordCache::factory()->create([
            'keyword' => 'existing',
            'language_code' => 'en',
            'location_code' => 2840,
        ]);

        $data = [
            [
                'keyword' => 'existing',
                'language_code' => 'en',
                'location_code' => 2840,
                'search_volume' => 2000,
            ],
            [
                'keyword' => 'new',
                'language_code' => 'en',
                'location_code' => 2840,
                'search_volume' => 1000,
            ],
        ];

        $updated = $this->repository->bulkUpdate($data);

        $this->assertEquals(2, $updated);
        $this->assertDatabaseHas('keyword_cache', ['keyword' => 'existing', 'search_volume' => 2000]);
        $this->assertDatabaseHas('keyword_cache', ['keyword' => 'new', 'search_volume' => 1000]);
    }

    public function test_delete_expired(): void
    {
        KeywordCache::factory()->create([
            'expires_at' => Carbon::now()->subDay(),
        ]);
        KeywordCache::factory()->create([
            'expires_at' => Carbon::now()->addDay(),
        ]);

        $deleted = $this->repository->deleteExpired();

        $this->assertEquals(1, $deleted);
        $this->assertDatabaseCount('keyword_cache', 1);
    }
}


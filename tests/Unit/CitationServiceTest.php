<?php

namespace Tests\Unit;

use App\Interfaces\CitationRepositoryInterface;
use App\Services\CitationService;
use App\Services\LLM\LLMClient;
use Mockery;
use Tests\TestCase;

class CitationServiceTest extends TestCase
{
    public function test_generate_queries_fills_requested_count(): void
    {
        $llm = Mockery::mock(LLMClient::class);
        $llm->shouldReceive('generateQueries')
            ->andReturnUsing(function ($url, $count) {
                return $this->fakeQueries($count);
            })
            ->zeroOrMoreTimes();

        $repository = Mockery::mock(CitationRepositoryInterface::class)->shouldIgnoreMissing();

        $service = new CitationService($repository, $llm);

        $queries = $service->generateQueries('https:

        $this->assertCount(150, $queries);
        $this->assertEquals(150, count(array_unique($queries)));
    }

    private function fakeQueries(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        return array_map(fn ($i) => "llm suggestion {$i}", range(1, $count));
    }
}

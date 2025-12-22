<?php

namespace Tests\Unit;

use App\Models\Faq;
use App\Repositories\FaqRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected FaqRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new FaqRepository();
    }

    public function test_create_faq(): void
    {
        $faq = $this->repository->create([
            'input' => 'test topic',
            'faqs' => [
                ['question' => 'What is test?', 'answer' => 'Test is...'],
            ],
            'user_id' => 1,
        ]);

        $this->assertInstanceOf(Faq::class, $faq);
        $this->assertEquals('test topic', $faq->input);
    }

    public function test_find_by_input_returns_faq(): void
    {
        Faq::factory()->create([
            'input' => 'test topic',
        ]);

        $faq = $this->repository->findByInput('test topic');

        $this->assertInstanceOf(\App\DTOs\FaqResponseDTO::class, $faq);
    }

    public function test_find_by_input_returns_null_when_not_found(): void
    {
        $faq = $this->repository->findByInput('nonexistent');
        $this->assertNull($faq);
    }
}


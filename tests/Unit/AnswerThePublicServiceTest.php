<?php

namespace Tests\Unit;

use App\Services\Keyword\AnswerThePublicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnswerThePublicServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AnswerThePublicService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AnswerThePublicService();
    }

    public function test_get_keyword_data_returns_array(): void
    {
        $result = $this->service->getKeywordData('seo tools', 'en');
        $this->assertIsArray($result);
    }
}


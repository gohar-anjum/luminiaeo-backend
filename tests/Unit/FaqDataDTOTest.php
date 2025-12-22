<?php

namespace Tests\Unit;

use App\DTOs\FaqDataDTO;
use Tests\TestCase;

class FaqDataDTOTest extends TestCase
{
    public function test_faq_data_dto_creation(): void
    {
        $dto = new FaqDataDTO(
            question: 'What is SEO?',
            answer: 'SEO is search engine optimization.',
        );

        $this->assertEquals('What is SEO?', $dto->question);
        $this->assertEquals('SEO is search engine optimization.', $dto->answer);
    }

    public function test_faq_data_dto_to_array(): void
    {
        $dto = new FaqDataDTO(
            question: 'Test?',
            answer: 'Test answer',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('Test?', $array['question']);
    }
}


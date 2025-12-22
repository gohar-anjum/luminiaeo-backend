<?php

namespace Tests\Unit;

use App\DTOs\FaqGenerationDTO;
use Tests\TestCase;

class FaqGenerationDTOTest extends TestCase
{
    public function test_faq_generation_dto_creation(): void
    {
        $dto = new FaqGenerationDTO(
            input: 'test topic',
            options: ['count' => 10],
        );

        $this->assertEquals('test topic', $dto->input);
        $this->assertEquals(10, $dto->options['count']);
    }
}


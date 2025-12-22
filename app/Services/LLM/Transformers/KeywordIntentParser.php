<?php

namespace App\Services\LLM\Transformers;

use App\Services\LLM\Support\JsonExtractor;

class   KeywordIntentParser
{
    public function parse(string $text): array
    {
        $json = JsonExtractor::extract($text);

        return $json ? json_decode($json, true) : [
            'intent' => 'unknown',
            'confidence' => 0,
        ];
    }
}

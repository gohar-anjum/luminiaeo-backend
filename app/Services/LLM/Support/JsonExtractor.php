<?php

namespace App\Services\LLM\Support;

class JsonExtractor
{
    public static function extract(string $text): ?string
    {
        if (preg_match('/```json(.*?)```/si', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) {
            return trim($m[0]);
        }

        if (preg_match('/\[(?:[^\[\]]|(?R))*\]/s', $text, $m)) {
            return trim($m[0]);
        }

        return null;
    }
}

<?php

namespace App\Services\LLM\Drivers;

use App\Services\LLM\Drivers\Contracts\LLMProviderInterface;
use Illuminate\Support\Facades\Http;

class GeminiDriver implements LLMProviderInterface
{
    public function name(): string
    {
        return 'gemini';
    }

    public function isAvailable(): bool
    {
        return !empty(config('services.gemini.key'));
    }

    public function send(array $messages): array
    {
        $response = Http::timeout(30)
            ->retry(3, 2)
            ->post(config('services.gemini.chat_url'), [
                'contents' => [
                    ['parts' => [['text' => json_encode($messages)]]]
                ]
            ]);

        if ($response->failed()) {
            throw new \Exception("Gemini API error: " . $response->body());
        }

        return $response->json();
    }
}

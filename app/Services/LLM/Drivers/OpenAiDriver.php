<?php

namespace App\Services\LLM\Drivers;

use App\Services\LLM\Drivers\Contracts\LLMProviderInterface;
use Illuminate\Support\Facades\Http;

class OpenAiDriver implements LLMProviderInterface
{
    public function name(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return !empty(config('services.openai.key'));
    }

    public function send(array $messages): array
    {
        $response = Http::withToken(config('services.openai.key'))
            ->timeout(30)
            ->retry(3, 2)
            ->post(config('services.openai.chat_url'), [
                'model'    => config('services.openai.model'),
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new \Exception("OpenAI API error: " . $response->body());
        }

        return $response->json();
    }
}

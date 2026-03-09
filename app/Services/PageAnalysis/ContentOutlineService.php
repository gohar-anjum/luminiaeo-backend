<?php

namespace App\Services\PageAnalysis;

use App\Models\ContentOutline;
use App\Services\LLM\Prompt\PlaceholderReplacer;
use App\Services\LLM\Prompt\PromptLoader;
use App\Services\LLM\Support\JsonExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentOutlineService
{
    public function __construct(
        protected PromptLoader $prompts,
        protected PlaceholderReplacer $replacer,
    ) {}

    /**
     * Generate a semantic SEO content outline for a keyword and tone.
     */
    public function generate(string $keyword, string $tone = 'professional'): array
    {
        $userId = auth()->id();
        $cooldownSeconds = (int) config('services.page_analysis.cache_ttl', 86400);

        $recent = ContentOutline::where('user_id', $userId)
            ->where('keyword', $keyword)
            ->where('tone', $tone)
            ->where('generated_at', '>=', now()->subSeconds($cooldownSeconds))
            ->latest('generated_at')
            ->first();

        if ($recent) {
            return [
                'outline' => $recent->outline,
                'semantic_keywords' => $recent->semantic_keywords ?? [],
                'intent' => $recent->intent,
                'keyword' => $recent->keyword,
                'tone' => $recent->tone,
                'from_cache' => true,
                'generated_at' => $recent->generated_at->toIso8601String(),
            ];
        }

        $generated = $this->callAi($keyword, $tone);

        $outline = ContentOutline::create([
            'user_id' => $userId,
            'keyword' => $keyword,
            'tone' => $tone,
            'outline' => $generated['sections'] ?? [],
            'semantic_keywords' => $generated['semantic_keywords'] ?? [],
            'intent' => $generated['intent'] ?? null,
            'generated_at' => now(),
        ]);

        return [
            'outline' => [
                'title' => $generated['title'] ?? '',
                'estimated_word_count' => $generated['estimated_word_count'] ?? null,
                'sections' => $generated['sections'] ?? [],
                'faq_suggestions' => $generated['faq_suggestions'] ?? [],
            ],
            'semantic_keywords' => $generated['semantic_keywords'] ?? [],
            'intent' => $generated['intent'] ?? null,
            'keyword' => $keyword,
            'tone' => $tone,
            'from_cache' => false,
            'generated_at' => $outline->generated_at->toIso8601String(),
        ];
    }

    protected function callAi(string $keyword, string $tone): array
    {
        $providers = $this->getProviders();

        foreach ($providers as $name => $config) {
            try {
                $result = $this->callProvider($name, $config, $keyword, $tone);
                if ($result) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::warning("Content outline generation failed with {$name}", ['error' => $e->getMessage()]);
            }
        }

        throw new \RuntimeException('All AI providers failed to generate content outline');
    }

    protected function callProvider(string $name, array $config, string $keyword, string $tone): ?array
    {
        $template = $this->prompts->load('content/outline_generation');
        $userPrompt = $this->replacer->replace($template['user'] ?? '', [
            'keyword' => $keyword,
            'tone' => $tone,
        ]);

        $messages = [
            ['role' => 'system', 'content' => $template['system'] ?? ''],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        if ($name === 'openai') {
            return $this->callOpenAi($config, $messages);
        }

        return $this->callGemini($config, $messages);
    }

    protected function callOpenAi(array $config, array $messages): ?array
    {
        $response = Http::withToken($config['api_key'])
            ->timeout($config['timeout'] ?? 60)
            ->retry(2, 1000)
            ->post(rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions', [
                'model' => $config['model'] ?? 'gpt-4o',
                'temperature' => 0.4,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            return null;
        }

        $text = $response->json('choices.0.message.content', '');
        return $this->parseResponse($text);
    }

    protected function callGemini(array $config, array $messages): ?array
    {
        $prompt = collect($messages)
            ->map(fn ($m) => strtoupper($m['role']) . ': ' . trim($m['content']))
            ->implode("\n\n");

        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            $config['model'] ?? 'gemini-1.5-pro',
            $config['api']
        );

        $response = Http::timeout($config['timeout'] ?? 60)
            ->retry(2, 1000)
            ->post($endpoint, [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.4],
            ]);

        if ($response->failed()) {
            return null;
        }

        $text = $response->json('candidates.0.content.parts.0.text', '');
        return $this->parseResponse($text);
    }

    protected function parseResponse(string $text): ?array
    {
        $json = JsonExtractor::extract($text) ?? $text;
        $parsed = json_decode($json, true);

        if (!is_array($parsed) || empty($parsed['sections'])) {
            return null;
        }

        return $parsed;
    }

    protected function getProviders(): array
    {
        $providers = [];

        $openai = config('citations.openai');
        if (!empty($openai['api_key'])) {
            $providers['openai'] = $openai;
        }

        $gemini = config('citations.gemini');
        if (!empty($gemini['api'])) {
            $providers['gemini'] = $gemini;
        }

        return $providers;
    }
}

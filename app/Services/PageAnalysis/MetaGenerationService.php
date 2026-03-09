<?php

namespace App\Services\PageAnalysis;

use App\Services\LLM\Prompt\PlaceholderReplacer;
use App\Services\LLM\Prompt\PromptLoader;
use App\Services\LLM\Support\JsonExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaGenerationService
{
    public function __construct(
        protected PromptLoader $prompts,
        protected PlaceholderReplacer $replacer,
    ) {}

    /**
     * Generate optimized title, description, and suggestions.
     * Attempts AI generation first, falls back to rule-based templates.
     */
    public function generate(array $analysis, ?string $targetKeyword = null): array
    {
        $keywords = $analysis['analysis']['keywords'] ?? [];
        $intent = $analysis['analysis']['intent'] ?? 'informational';
        $primaryKeyword = $targetKeyword ?: $this->getPrimaryKeyword($keywords);
        $existingTitle = $analysis['meta']['title'] ?? '';
        $existingDescription = $analysis['meta']['description'] ?? '';

        $aiResult = $this->tryAiGeneration(
            $primaryKeyword,
            $existingTitle,
            $existingDescription,
            $intent,
            $keywords,
            $analysis['content']['word_count'] ?? 0,
        );

        if ($aiResult) {
            return [
                'title' => Str::limit($aiResult['title'] ?? '', 60, ''),
                'description' => Str::limit($aiResult['description'] ?? '', 160, ''),
                'suggestions' => $aiResult['suggestions'] ?? [],
                'primary_keyword' => $primaryKeyword,
                'intent' => $intent,
            ];
        }

        return $this->fallbackGeneration($primaryKeyword, $intent, $keywords, $existingTitle, $existingDescription);
    }

    protected function tryAiGeneration(
        string $keyword,
        string $existingTitle,
        string $existingDescription,
        string $intent,
        array $keywords,
        int $wordCount,
    ): ?array {
        $openaiKey = config('citations.openai.api_key');
        if (empty($openaiKey)) {
            return null;
        }

        try {
            $template = $this->prompts->load('meta/optimization');
            $keywordPhrases = implode(', ', array_slice(
                array_map(fn ($k) => is_array($k) ? ($k['phrase'] ?? '') : (string) $k, $keywords),
                0, 5
            ));

            $userPrompt = $this->replacer->replace($template['user'] ?? '', [
                'keyword' => $keyword,
                'existing_title' => $existingTitle ?: '(none)',
                'existing_description' => $existingDescription ?: '(none)',
                'intent' => $intent,
                'page_keywords' => $keywordPhrases,
                'word_count' => (string) $wordCount,
            ]);

            $response = Http::withToken($openaiKey)
                ->timeout(config('citations.openai.timeout', 30))
                ->retry(2, 1000)
                ->post(rtrim(config('citations.openai.base_url', 'https://api.openai.com/v1'), '/') . '/chat/completions', [
                    'model' => config('citations.openai.model', 'gpt-4o'),
                    'temperature' => 0.3,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $template['system'] ?? ''],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('Meta AI generation failed', ['status' => $response->status()]);
                return null;
            }

            $text = $response->json('choices.0.message.content', '');
            $json = JsonExtractor::extract($text) ?? $text;
            $parsed = json_decode($json, true);

            if (!is_array($parsed) || empty($parsed['title'])) {
                return null;
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('Meta AI generation exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    protected function fallbackGeneration(
        string $keyword,
        string $intent,
        array $keywords,
        string $existingTitle,
        string $existingDescription,
    ): array {
        $title = $this->optimizeTitle($keyword, $intent);
        $description = $this->optimizeDescription($keyword, $intent, $keywords);

        $suggestions = [];
        if (empty($existingTitle)) {
            $suggestions[] = 'Page is missing a meta title. A keyword-optimized title has been generated.';
        } elseif (!str_contains(strtolower($existingTitle), strtolower($keyword))) {
            $suggestions[] = 'Current title does not contain the target keyword. The optimized version front-loads it.';
        }
        if (empty($existingDescription)) {
            $suggestions[] = 'Page is missing a meta description. A keyword-rich description has been generated.';
        } elseif (strlen($existingDescription) < 100) {
            $suggestions[] = 'Current description is too short (' . strlen($existingDescription) . ' chars). Expanded to ~155 chars for better SERP visibility.';
        }

        return [
            'title' => Str::limit($title, 60, ''),
            'description' => Str::limit($description, 160, ''),
            'suggestions' => $suggestions,
            'primary_keyword' => $keyword,
            'intent' => $intent,
        ];
    }

    protected function getPrimaryKeyword(array $keywords): string
    {
        if (empty($keywords)) {
            return 'Page';
        }
        $first = $keywords[0];
        return is_array($first) ? ($first['phrase'] ?? 'Page') : (string) $first;
    }

    protected function optimizeTitle(string $keyword, string $intent): string
    {
        return match ($intent) {
            'commercial' => "{$keyword} – Best Solutions & Pricing",
            'comparative' => "{$keyword} – Comparison & Review",
            default => "{$keyword} – Complete Guide",
        };
    }

    protected function optimizeDescription(string $keyword, string $intent, array $keywords): string
    {
        $phrases = array_slice(
            array_map(fn ($k) => is_array($k) ? $k['phrase'] : $k, $keywords),
            0, 3
        );
        $keywordPhrase = implode(', ', $phrases ?: [$keyword]);

        return match ($intent) {
            'commercial' => "Discover the best {$keyword}. Compare prices, features & get the best deal. {$keywordPhrase}.",
            'comparative' => "In-depth comparison of {$keyword}. See side-by-side analysis. {$keywordPhrase}.",
            default => "Learn everything about {$keyword}. Complete guide with expert tips. {$keywordPhrase}.",
        };
    }
}

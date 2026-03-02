<?php

namespace App\Services\PageAnalysis;

use App\Models\MetaAnalysis;
use App\Exceptions\PageAnalysisException;
use Illuminate\Http\Client\RequestException;

class MetaOptimizerService
{
    public function __construct(
        protected AnalysisClient $analysisClient,
        protected MetaGenerationService $metaGenerationService
    ) {}

    /**
     * Analyze URL, generate optimized meta tags, persist, and return.
     * Respects 24-hour re-analysis cooldown per user+URL.
     *
     * @return array{
     *     title: string,
     *     description: string,
     *     from_cache?: bool,
     *     analyzed_at?: string,
     *     primary_keyword?: string|null,
     *     intent?: string|null
     * }
     */
    public function handle(string $url): array
    {
        $userId = auth()->id();

        $recent = MetaAnalysis::where('user_id', $userId)
            ->where('url', $url)
            ->where('analyzed_at', '>=', now()->subDay())
            ->latest('analyzed_at')
            ->first();

        if ($recent) {
            return [
                'title' => $recent->suggested_title,
                'description' => $recent->suggested_description,
                'from_cache' => true,
                'analyzed_at' => $recent->analyzed_at->toIso8601String(),
                'primary_keyword' => $this->resolvePrimaryKeyword($recent->keywords ?? []),
                'intent' => $recent->intent,
            ];
        }

        try {
            $analysis = $this->analysisClient->analyze([
                'url' => $url,
                'analysis' => ['keywords'],
            ]);
        } catch (RequestException $e) {
            throw PageAnalysisException::fromClientException($e);
        }

        $generated = $this->metaGenerationService->generate($analysis);
        $keywords = $analysis['analysis']['keywords'] ?? [];
        $intent = $analysis['analysis']['intent'] ?? $generated['intent'] ?? null;

        $meta = MetaAnalysis::create([
            'user_id' => $userId,
            'url' => $url,
            'original_title' => $analysis['meta']['title'] ?? null,
            'original_description' => $analysis['meta']['description'] ?? null,
            'suggested_title' => $generated['title'],
            'suggested_description' => $generated['description'],
            'keywords' => $keywords,
            'intent' => $intent,
            'word_count' => $analysis['content']['word_count'] ?? 0,
            'analyzed_at' => now(),
        ]);

        return [
            'title' => $generated['title'],
            'description' => $generated['description'],
            'from_cache' => false,
             'analyzed_at' => $meta->analyzed_at->toIso8601String(),
             'primary_keyword' => $generated['primary_keyword'] ?? $this->resolvePrimaryKeyword($keywords),
             'intent' => $intent,
        ];
    }

    /**
     * Safely resolve a primary keyword from a stored keywords array.
     */
    protected function resolvePrimaryKeyword(array $keywords): ?string
    {
        if (empty($keywords)) {
            return null;
        }
        $first = $keywords[0];
        return is_array($first) ? ($first['phrase'] ?? null) : (string) $first;
    }
}

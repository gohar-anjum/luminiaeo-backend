<?php

namespace App\Services\PageAnalysis;

use App\Exceptions\PageAnalysisException;
use App\Models\MetaAnalysis;
use Illuminate\Http\Client\RequestException;

class MetaOptimizerService
{
    public function __construct(
        protected AnalysisClient $analysisClient,
        protected MetaGenerationService $metaGenerationService
    ) {}

    public function handle(string $url, ?string $keyword = null): array
    {
        $userId = auth()->id();
        $cooldownSeconds = (int) config('services.page_analysis.cache_ttl', 86400);

        $query = MetaAnalysis::where('user_id', $userId)
            ->where('url', $url)
            ->where('analyzed_at', '>=', now()->subSeconds($cooldownSeconds))
            ->latest('analyzed_at');

        if ($keyword) {
            $query->where('target_keyword', $keyword);
        } else {
            $query->whereNull('target_keyword');
        }

        $recent = $query->first();

        if ($recent) {
            return [
                'title' => $recent->suggested_title,
                'description' => $recent->suggested_description,
                'suggestions' => $recent->suggestions ?? [],
                'from_cache' => true,
                'analyzed_at' => $recent->analyzed_at->toIso8601String(),
                'primary_keyword' => $recent->target_keyword ?: $this->resolvePrimaryKeyword($recent->keywords ?? []),
                'intent' => $recent->intent,
                'original_title' => $recent->original_title,
                'original_description' => $recent->original_description,
            ];
        }

        try {
            $payload = [
                'url' => $url,
                'analysis' => ['keywords'],
            ];
            if ($keyword) {
                $payload['keyword'] = $keyword;
            }

            $analysis = $this->analysisClient->analyze($payload);
        } catch (RequestException $e) {
            throw PageAnalysisException::fromClientException($e);
        }

        $generated = $this->metaGenerationService->generate($analysis, $keyword);
        $keywords = $analysis['analysis']['keywords'] ?? [];
        $intent = $analysis['analysis']['intent'] ?? $generated['intent'] ?? null;

        $meta = MetaAnalysis::create([
            'user_id' => $userId,
            'url' => $url,
            'target_keyword' => $keyword ?: ($generated['primary_keyword'] ?? null),
            'original_title' => $analysis['meta']['title'] ?? null,
            'original_description' => $analysis['meta']['description'] ?? null,
            'suggested_title' => $generated['title'],
            'suggested_description' => $generated['description'],
            'suggestions' => $generated['suggestions'] ?? [],
            'keywords' => $keywords,
            'intent' => $intent,
            'word_count' => $analysis['content']['word_count'] ?? 0,
            'analyzed_at' => now(),
        ]);

        return [
            'title' => $generated['title'],
            'description' => $generated['description'],
            'suggestions' => $generated['suggestions'] ?? [],
            'from_cache' => false,
            'analyzed_at' => $meta->analyzed_at->toIso8601String(),
            'primary_keyword' => $generated['primary_keyword'] ?? $this->resolvePrimaryKeyword($keywords),
            'intent' => $intent,
            'original_title' => $analysis['meta']['title'] ?? null,
            'original_description' => $analysis['meta']['description'] ?? null,
        ];
    }

    protected function resolvePrimaryKeyword(array $keywords): ?string
    {
        if (empty($keywords)) {
            return null;
        }
        $first = $keywords[0];

        return is_array($first) ? ($first['phrase'] ?? null) : (string) $first;
    }
}

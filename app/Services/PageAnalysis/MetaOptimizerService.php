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

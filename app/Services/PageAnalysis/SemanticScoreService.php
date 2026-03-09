<?php

namespace App\Services\PageAnalysis;

use App\Models\SemanticAnalysis;

class SemanticScoreService
{
    public function __construct(
        protected AnalysisClient $analysisClient
    ) {}

    /**
     * Evaluate semantic relevance of a page for a keyword.
     * Returns rich data: overall score, per-keyword breakdown, primary keyword.
     */
    public function evaluate(string $url, ?string $keyword = null): array
    {
        $userId = auth()->id();
        $cooldownSeconds = (int) config('services.page_analysis.cache_ttl', 86400);

        $query = SemanticAnalysis::where('user_id', $userId)
            ->where('source_url', $url)
            ->where('analyzed_at', '>=', now()->subSeconds($cooldownSeconds))
            ->latest('analyzed_at');

        if ($keyword) {
            $query->where('target_keyword', $keyword);
        }

        $recent = $query->first();

        if ($recent) {
            return [
                'semantic_score' => $recent->semantic_score,
                'primary_keyword' => $recent->target_keyword ?: $recent->comparison_value,
                'keyword_scores' => $recent->keyword_scores ?? [],
                'from_cache' => true,
                'analyzed_at' => $recent->analyzed_at->toIso8601String(),
            ];
        }

        $payload = [
            'url' => $url,
            'analysis' => ['semantic_score', 'keywords'],
        ];
        if ($keyword) {
            $payload['keyword'] = $keyword;
        }

        $analysis = $this->analysisClient->analyze($payload);

        $score = (float) ($analysis['analysis']['semantic_score'] ?? 0);
        $primaryKeyword = $analysis['analysis']['primary_keyword'] ?? null;
        $keywordScores = $analysis['analysis']['keyword_scores'] ?? [];

        if (!$primaryKeyword) {
            $keywords = $analysis['analysis']['keywords'] ?? [];
            if (!empty($keywords)) {
                $first = $keywords[0];
                $primaryKeyword = is_array($first) ? ($first['phrase'] ?? null) : (string) $first;
            }
        }

        SemanticAnalysis::create([
            'user_id' => $userId,
            'source_url' => $url,
            'target_keyword' => $keyword ?: $primaryKeyword,
            'comparison_type' => 'self',
            'comparison_value' => $primaryKeyword ?? '',
            'semantic_score' => $score,
            'keyword_scores' => $keywordScores,
            'analyzed_at' => now(),
        ]);

        return [
            'semantic_score' => $score,
            'primary_keyword' => $keyword ?: $primaryKeyword,
            'keyword_scores' => $keywordScores,
            'from_cache' => false,
            'analyzed_at' => now()->toIso8601String(),
        ];
    }
}

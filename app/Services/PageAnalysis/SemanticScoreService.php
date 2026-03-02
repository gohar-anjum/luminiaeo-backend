<?php

namespace App\Services\PageAnalysis;

use App\Models\SemanticAnalysis;

class SemanticScoreService
{
    public function __construct(
        protected AnalysisClient $analysisClient
    ) {}

    /**
     * Evaluate how well a page semantically covers its primary topic.
     * User provides only URL; system extracts primary keyword and compares
     * page embedding vs primary keyword embedding.
     */
    public function evaluate(string $url): float
    {
        $analysis = $this->analysisClient->analyze([
            'url' => $url,
            'analysis' => ['semantic_score', 'keywords'],
        ]);

        $score = (float) ($analysis['analysis']['semantic_score'] ?? 0);

        $keywords = $analysis['analysis']['keywords'] ?? [];
        $primaryKeyword = null;
        if (! empty($keywords)) {
            $first = $keywords[0];
            $primaryKeyword = is_array($first) ? ($first['phrase'] ?? null) : (string) $first;
        }

        SemanticAnalysis::create([
            'user_id' => auth()->id(),
            'source_url' => $url,
            'comparison_type' => 'self',
            'comparison_value' => $primaryKeyword ?? '',
            'semantic_score' => $score,
            'analyzed_at' => now(),
        ]);

        return $score;
    }
}

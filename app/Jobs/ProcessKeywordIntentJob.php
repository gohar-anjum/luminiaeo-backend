<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Services\LLM\LLMClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessKeywordIntentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 300; // 5 minutes

    public function __construct(
        public int $keywordId
    ) {
    }

    public function handle(LLMClient $llmClient): void
    {
        $keyword = Keyword::find($this->keywordId);

        if (!$keyword) {
            Log::warning('Keyword not found for intent analysis', ['keyword_id' => $this->keywordId]);
            return;
        }

        // Skip if already analyzed
        if ($keyword->intent_category && $keyword->ai_visibility_score !== null) {
            return;
        }

        try {
            $analysis = $llmClient->analyzeKeywordIntent($keyword->keyword);

            $keyword->update([
                'intent_category' => $analysis['intent_category'],
                'intent' => $analysis['intent'],
                'intent_metadata' => [
                    'difficulty' => $analysis['difficulty'],
                    'required_entities' => $analysis['required_entities'],
                    'competitiveness' => $analysis['competitiveness'],
                    'structured_data_helpful' => $analysis['structured_data_helpful'],
                    'explanation' => $analysis['explanation'],
                ],
                'ai_visibility_score' => $analysis['ai_visibility_score'],
            ]);

            Log::info('Keyword intent analysis completed', [
                'keyword_id' => $keyword->id,
                'keyword' => $keyword->keyword,
                'ai_visibility_score' => $analysis['ai_visibility_score'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Keyword intent analysis failed', [
                'keyword_id' => $keyword->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}


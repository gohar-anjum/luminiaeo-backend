<?php

namespace App\Jobs;

use App\Models\FaqTask;
use App\Services\FAQ\AlsoAskedService;
use App\Services\FAQ\FaqGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessFaqTask implements ShouldQueue
{
    use Queueable;

    protected int $taskId;

    public function __construct(int $taskId)
    {
        $this->taskId = $taskId;
    }

    public function handle(AlsoAskedService $alsoAskedService, FaqGeneratorService $faqGeneratorService): void
    {
        $task = FaqTask::find($this->taskId);
        
        if (!$task || $task->isCompleted()) {
            return;
        }

        // Allow retrying failed tasks - reset status if it was failed
        if ($task->isFailed()) {
            $task->update([
                'status' => 'pending',
                'error_message' => null,
            ]);
        }

        $task->markAsProcessing();

        try {
            $input = $task->url ?? $task->topic ?? '';
            $sourceHash = $faqGeneratorService->getSourceHash($input, $task->options ?? []);
            
            $existingFaq = $faqGeneratorService->findExistingFaq($input, $task->options ?? []);
            
            if ($existingFaq) {
                $faqGeneratorService->incrementApiCallsSaved($existingFaq->id);
                $existingFaq->refresh();

                $task->markAsCompleted($existingFaq->id);
                return;
            }

              if (!empty($task->alsoasked_questions) && is_array($task->alsoasked_questions)) {
                $existingFaq = $faqGeneratorService->findExistingFaq($input, $task->options ?? []);
                if ($existingFaq) {
                    $faqGeneratorService->incrementApiCallsSaved($existingFaq->id);
                    $existingFaq->refresh();

                    $task->markAsCompleted($existingFaq->id);
                    return;
                }

                // Use existing AlsoAsked questions from task, skip API call
                $alsoAskedQuestions = $task->alsoasked_questions;
            } else {
                // AlsoAsked questions not present, fetch them
                $alsoAskedQuestions = [];
                
                if (!empty($task->alsoasked_search_id) && is_string($task->alsoasked_search_id)) {
                    $searchResults = $alsoAskedService->getSearchResults($task->alsoasked_search_id);

                    if (!$searchResults) {
                        throw new \RuntimeException('Failed to get AlsoAsked search results');
                    }

                    $status = $searchResults['status'] ?? 'unknown';

                    if ($status === 'running') {
                        dispatch(new self($this->taskId))->delay(now()->addSeconds(5));
                        return;
                    }

                    if ($status === 'success' || $status === 'no_records') {
                        if ($status === 'success') {
                            $alsoAskedQuestions = $alsoAskedService->extractQuestions($searchResults);
                        }
                        $task->update(['alsoasked_questions' => $alsoAskedQuestions]);
                    } else {
                        dispatch(new self($this->taskId))->delay(now()->addSeconds(5));
                        return;
                    }
                }
            }

            // Process FAQs with SERP questions and (if available) AlsoAsked questions
            $allQuestions = $faqGeneratorService->combineQuestions(
                $task->serp_questions ?? [],
                $alsoAskedQuestions
            );

            if (empty($allQuestions)) {
                $task->markAsFailed('No questions available after combining SERP and AlsoAsked results');
                return;
            }

            // Save combined questions immediately so frontend can display them
            // This happens as soon as AlsoAsked response is received
            $task->update(['serp_questions' => $allQuestions]);

            $languageCode = $task->options['language_code'] ?? config('services.faq.default_language', 'en');
            $locationCode = $task->options['location_code'] ?? config('services.faq.default_location', 2840);

            $topKeywords = [];
            $hasValidKeywords = false;
            
            if (!empty($task->question_keywords) && is_array($task->question_keywords)) {
                if (isset($task->question_keywords['top_keywords']) && is_array($task->question_keywords['top_keywords']) && !empty($task->question_keywords['top_keywords'])) {
                    $topKeywords = $task->question_keywords['top_keywords'];
                    $hasValidKeywords = true;
                }
            }
            
            if (!$hasValidKeywords) {
                try {
                    $topKeywords = $faqGeneratorService->fetchKeywordsForTopic(
                        $task->topic,
                        $languageCode,
                        $locationCode
                    );
                    
                    if (!empty($topKeywords)) {
                        $task->update(['question_keywords' => ['top_keywords' => $topKeywords]]);
                    }
                } catch (\Exception $keywordException) {
                    $topKeywords = [];
                }
            }

            $urlContent = null;
            if ($task->url) {
                $urlContent = $faqGeneratorService->fetchUrlContent($task->url);
            }

            // Try Gemini first, then GPT, then SERP fallback
            $faqs = null;
            $lastException = null;

            // Try Gemini
            try {
                $faqs = $faqGeneratorService->generateFaqsWithGemini(
                    $task->url,
                    $task->topic,
                    $urlContent,
                    $allQuestions,
                    $task->options ?? [],
                    $topKeywords
                );
            } catch (\Exception $geminiException) {
                $lastException = $geminiException;

                // Try GPT fallback
                try {
                    $faqs = $faqGeneratorService->generateFaqsWithGPT(
                        $task->url,
                        $task->topic,
                        $urlContent,
                        $allQuestions,
                        $task->options ?? [],
                        $topKeywords
                    );
                } catch (\Exception $gptException) {
                    $lastException = $gptException;

                    // Final fallback to SERP response
                    $serpResponse = $faqGeneratorService->fetchSerpResponse(
                        $task->url,
                        $task->topic
                    );

                    if ($serpResponse && !empty($serpResponse)) {
                        $faqs = $faqGeneratorService->generateFaqsFromSerpResponse(
                            $task->url,
                            $task->topic,
                            $serpResponse
                        );

                        if (empty($faqs)) {
                            throw new \RuntimeException('Failed to generate FAQs from SERP response: No FAQs extracted');
                        }
                    } else {
                        // If all fallbacks fail, throw the last exception
                        throw new \RuntimeException('All LLM APIs failed and SERP fallback unavailable. Last error: ' . $lastException->getMessage());
                    }
                }
            }

            if (empty($faqs)) {
                throw new \RuntimeException('Failed to generate FAQs: No FAQs generated from any source');
            }

            // Source hash already calculated above, reuse it
            $faqRecord = $faqGeneratorService->storeFaqsInDatabase(
                $task->url,
                $task->topic,
                $faqs,
                $task->options ?? [],
                $sourceHash
            );

            if (!$faqRecord || !$faqRecord->id) {
                throw new \RuntimeException('Failed to store FAQs: Invalid FAQ record returned');
            }

            $task->markAsCompleted($faqRecord->id);

        } catch (\Exception $e) {
            $task->markAsFailed($e->getMessage());
        }
    }
}

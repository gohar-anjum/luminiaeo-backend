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
            // Ensure search_keyword is set (extract from topic or URL)
            if (empty($task->search_keyword)) {
                $searchKeyword = $task->topic ?? ($task->url ? parse_url($task->url, PHP_URL_HOST) : '');
                if ($searchKeyword) {
                    $task->update(['search_keyword' => $searchKeyword]);
                }
            }
            $keyword = $task->search_keyword ?? $task->topic ?? '';

            // PHASE 1: Process SERP questions immediately if not already processed
            if (!empty($task->serp_questions) && empty($task->serp_answers)) {
                $this->processSerpQuestions($task, $faqGeneratorService, $keyword);
                // Refresh task to get updated serp_answers
                $task->refresh();
            }

            // PHASE 2: Check for PAA (AlsoAsked) questions
            $paaQuestions = null;
            
            if (!empty($task->alsoasked_questions) && is_array($task->alsoasked_questions)) {
                // Use existing AlsoAsked questions from task
                $paaQuestions = $task->alsoasked_questions;
            } else {
                // AlsoAsked questions not present, fetch them
                if (!empty($task->alsoasked_search_id) && is_string($task->alsoasked_search_id)) {
                    $searchResults = $alsoAskedService->getSearchResults($task->alsoasked_search_id);

                    if (!$searchResults) {
                        // If SERP answers are ready, finalize with SERP only
                        $task->refresh();
                        if (!empty($task->serp_answers)) {
                            $this->finalizeTask($task, $faqGeneratorService, true);
                            return;
                        }
                        throw new \RuntimeException('Failed to get AlsoAsked search results');
                    }

                    $status = $searchResults['status'] ?? 'unknown';

                    if ($status === 'running') {
                        // Refresh task to check if SERP answers are ready
                        $task->refresh();
                        
                        // If SERP answers are ready, don't finalize yet - just make them available
                        // Continue waiting for PAA, then finalize with both
                        if (!empty($task->serp_answers)) {
                            // SERP answers are ready and available via status endpoint
                            // Continue waiting for PAA, then finalize with both
                            dispatch(new self($this->taskId))->delay(now()->addSeconds(5));
                            return;
                        }
                        
                        // No SERP answers yet, continue waiting for both
                        dispatch(new self($this->taskId))->delay(now()->addSeconds(5));
                        return;
                    }

                    if ($status === 'success' || $status === 'no_records') {
                        if ($status === 'success') {
                            $paaQuestions = $alsoAskedService->extractQuestions($searchResults);
                        }
                        $task->update(['alsoasked_questions' => $paaQuestions ?? []]);
                    } else {
                        // Refresh task to check if SERP answers are ready
                        $task->refresh();
                        
                        // If SERP answers are ready, finalize with SERP only
                        if (!empty($task->serp_answers)) {
                            $this->finalizeTask($task, $faqGeneratorService, true);
                            return;
                        }
                        dispatch(new self($this->taskId))->delay(now()->addSeconds(5));
                        return;
                    }
                }
            }

            // PHASE 2: Process PAA questions if available and not already processed
            if (!empty($paaQuestions) && empty($task->paa_answers)) {
                $this->processPaaQuestions($task, $faqGeneratorService, $keyword, $paaQuestions);
                // Refresh task to get updated paa_answers
                $task->refresh();
            }

            // Finalize task - combine all answers and store
            // Refresh one more time to ensure we have latest data
            $task->refresh();
            
            // Only finalize if we have at least SERP answers
            // If PAA is still pending, we'll finalize with SERP only
            if (!empty($task->serp_answers) || !empty($task->paa_answers)) {
                $this->finalizeTask($task, $faqGeneratorService, true);
            } else {
                // No answers yet, wait a bit more
                dispatch(new self($this->taskId))->delay(now()->addSeconds(5));
            }

        } catch (\Exception $e) {
            // If we have SERP answers, don't fail completely - just log the error and finalize
            $task->refresh();
            if (!empty($task->serp_answers)) {
                \Illuminate\Support\Facades\Log::error('PAA processing error but SERP answers available: ' . $e->getMessage());
                $this->finalizeTask($task, $faqGeneratorService, true);
            } else {
                $task->markAsFailed($e->getMessage());
            }
        }
    }

    /**
     * Process SERP questions and generate answers immediately.
     */
    protected function processSerpQuestions(
        FaqTask $task,
        FaqGeneratorService $faqGeneratorService,
        string $keyword
    ): void {
        $serpQuestions = $task->serp_questions ?? [];
        
        if (empty($serpQuestions)) {
            return;
        }

        $languageCode = $task->options['language_code'] ?? config('services.faq.default_language', 'en');
        $locationCode = $task->options['location_code'] ?? config('services.faq.default_location', 2840);

        // Get top keywords if not already available
        $topKeywords = [];
        if (!empty($task->question_keywords) && is_array($task->question_keywords)) {
            if (isset($task->question_keywords['top_keywords']) && is_array($task->question_keywords['top_keywords'])) {
                $topKeywords = $task->question_keywords['top_keywords'];
            }
        }

        if (empty($topKeywords) && $task->topic) {
            try {
                $topKeywords = $faqGeneratorService->fetchKeywordsForTopic(
                    $task->topic,
                    $languageCode,
                    $locationCode
                );
                
                if (!empty($topKeywords)) {
                    $currentKeywords = $task->question_keywords ?? [];
                    $currentKeywords['top_keywords'] = $topKeywords;
                    $task->update(['question_keywords' => $currentKeywords]);
                }
            } catch (\Exception $e) {
                // Continue without keywords
            }
        }

        try {
            // Generate answers for SERP questions
            $serpAnswers = $faqGeneratorService->generateAnswersForQuestions(
                $serpQuestions,
                $keyword,
                $task->url,
                $task->topic,
                $task->options ?? [],
                $topKeywords
            );

            // Store SERP answers immediately
            $task->update(['serp_answers' => $serpAnswers]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to generate SERP answers: ' . $e->getMessage());
            // Don't throw - allow PAA processing to continue
        }
    }

    /**
     * Process PAA (People Also Asked) questions and generate answers.
     */
    protected function processPaaQuestions(
        FaqTask $task,
        FaqGeneratorService $faqGeneratorService,
        string $keyword,
        array $paaQuestions
    ): void {
        if (empty($paaQuestions)) {
            return;
        }

        $languageCode = $task->options['language_code'] ?? config('services.faq.default_language', 'en');
        $locationCode = $task->options['location_code'] ?? config('services.faq.default_location', 2840);

        // Get top keywords if not already available
        $topKeywords = [];
        if (!empty($task->question_keywords) && is_array($task->question_keywords)) {
            if (isset($task->question_keywords['top_keywords']) && is_array($task->question_keywords['top_keywords'])) {
                $topKeywords = $task->question_keywords['top_keywords'];
            }
        }

        try {
            // Generate answers for PAA questions
            $paaAnswers = $faqGeneratorService->generateAnswersForQuestions(
                $paaQuestions,
                $keyword,
                $task->url,
                $task->topic,
                $task->options ?? [],
                $topKeywords
            );

            // Store PAA answers
            $task->update(['paa_answers' => $paaAnswers]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to generate PAA answers: ' . $e->getMessage());
            // Don't throw - we already have SERP answers
        }
    }

    /**
     * Finalize task by combining all answers and storing in database.
     * Can be called with SERP answers only, or with both SERP and PAA answers.
     */
    protected function finalizeTask(
        FaqTask $task,
        FaqGeneratorService $faqGeneratorService,
        bool $allowPartial = true
    ): void {
        // Refresh task to get latest data
        $task->refresh();
        
        $input = $task->url ?? $task->topic ?? '';
        $sourceHash = $faqGeneratorService->getSourceHash($input, $task->options ?? []);
        
        // Check for existing FAQ
        $existingFaq = $faqGeneratorService->findExistingFaq($input, $task->options ?? []);
        if ($existingFaq && $task->isCompleted()) {
            $faqGeneratorService->incrementApiCallsSaved($existingFaq->id);
            $existingFaq->refresh();
            return;
        }

        // Combine SERP and PAA answers
        $allFaqs = [];
        
        // Add SERP answers with source indicator
        if (!empty($task->serp_answers) && is_array($task->serp_answers)) {
            foreach ($task->serp_answers as $faq) {
                $faq['source'] = 'serp';
                $allFaqs[] = $faq;
            }
        }
        
        // Add PAA answers with source indicator
        if (!empty($task->paa_answers) && is_array($task->paa_answers)) {
            foreach ($task->paa_answers as $faq) {
                $faq['source'] = 'paa';
                $allFaqs[] = $faq;
            }
        }

        if (empty($allFaqs)) {
            if (!$allowPartial) {
                throw new \RuntimeException('No answers generated from any source');
            }
            // If allowing partial and no answers yet, just return (don't fail)
            return;
        }

        // If task already has an FAQ record, update it instead of creating new one
        if ($task->faq_id) {
            $existingFaq = \App\Models\Faq::find($task->faq_id);
            if ($existingFaq) {
                // Update existing FAQ with combined answers
                $existingFaq->update(['faqs' => $allFaqs]);
                $task->markAsCompleted($existingFaq->id);
                return;
            }
        }

        // Store combined FAQs in database
        $faqRecord = $faqGeneratorService->storeFaqsInDatabase(
            $task->url,
            $task->topic,
            $allFaqs,
            $task->options ?? [],
            $sourceHash
        );

        if (!$faqRecord || !$faqRecord->id) {
            throw new \RuntimeException('Failed to store FAQs: Invalid FAQ record returned');
        }

        $task->markAsCompleted($faqRecord->id);
    }
}

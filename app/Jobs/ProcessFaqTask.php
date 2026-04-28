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

    /**
     * When AlsoAsked HTTP returns empty, retry before giving up (5s × 48 ≈ 4 minutes).
     */
    private const ALSOASKED_NULL_RETRY_MAX = 48;

    protected int $taskId;

    public function __construct(int $taskId)
    {
        $this->taskId = $taskId;
    }

    public function handle(AlsoAskedService $alsoAskedService, FaqGeneratorService $faqGeneratorService): void
    {
        $task = FaqTask::find($this->taskId);

        if (! $task || $task->isCompleted()) {
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
            if (! empty($task->serp_questions) && empty($task->serp_answers)) {
                $this->processSerpQuestions($task, $faqGeneratorService, $keyword);
                // Refresh task to get updated serp_answers
                $task->refresh();
            }

            // PHASE 2: Resolve AlsoAsked into alsoasked_questions (null in DB = poll not finished yet)
            $task->refresh();
            if (! $this->isAlsoAskedPhaseResolved($task)) {
                if (! $this->pollAlsoAskedAndPersist($task, $alsoAskedService)) {
                    return;
                }
                $task->refresh();
            }

            // Derive PAA list from task (includes [] when AlsoAsked returned no questions)
            $paaQuestions = [];
            if (is_array($task->alsoasked_questions)) {
                $paaQuestions = $task->alsoasked_questions;
                $serpCount = count($task->serp_questions ?? []);
                $maxPaaQuestions = max(0, 10 - $serpCount);
                if ($maxPaaQuestions === 0) {
                    $paaQuestions = [];
                } elseif (count($paaQuestions) > $maxPaaQuestions) {
                    $paaQuestions = array_slice($paaQuestions, 0, $maxPaaQuestions);
                }
            }

            if (! empty($paaQuestions) && empty($task->paa_answers)) {
                $this->processPaaQuestions($task, $faqGeneratorService, $keyword, $paaQuestions);
                $task->refresh();
            }

            $task->refresh();

            if (! empty($task->serp_answers) || ! empty($task->paa_answers)) {
                $this->finalizeTask($task, $faqGeneratorService, true);
            } else {
                dispatch(new self($this->taskId))->delay(now()->addSeconds(5));
            }

        } catch (\Exception $e) {
            $task->refresh();
            if (! empty($task->serp_answers) && $this->isAlsoAskedPhaseResolved($task)) {
                \Illuminate\Support\Facades\Log::error('FAQ task error after SERP answers; finalizing: '.$e->getMessage());
                $this->finalizeTask($task, $faqGeneratorService, true);
            } else {
                $task->markAsFailed($e->getMessage());
            }
        }
    }

    protected function isAlsoAskedPhaseResolved(FaqTask $task): bool
    {
        if (empty($task->alsoasked_search_id) || ! is_string($task->alsoasked_search_id)) {
            return true;
        }

        return $task->alsoasked_questions !== null;
    }

    /**
     * Poll AlsoAsked until we can persist alsoasked_questions. Returns false if the worker should
     * stop and a delayed job was scheduled.
     */
    protected function pollAlsoAskedAndPersist(FaqTask $task, AlsoAskedService $alsoAskedService): bool
    {
        $task->refresh();
        if ($this->isAlsoAskedPhaseResolved($task)) {
            \Illuminate\Support\Facades\Log::info('FAQ task AlsoAsked already resolved', [
                'task_id' => $task->id,
                'alsoasked_search_id' => $task->alsoasked_search_id,
                'status' => $task->status,
            ]);
            return true;
        }

        \Illuminate\Support\Facades\Log::info('FAQ task polling AlsoAsked', [
            'task_id' => $task->id,
            'alsoasked_search_id' => $task->alsoasked_search_id,
            'status' => $task->status,
        ]);
        $searchResults = $alsoAskedService->getSearchResults($task->alsoasked_search_id);

        if (! $searchResults) {
            $attempts = (int) (($task->options ?? [])['_alsoasked_null_results'] ?? 0);
            \Illuminate\Support\Facades\Log::warning('FAQ task AlsoAsked returned empty/null results', [
                'task_id' => $task->id,
                'alsoasked_search_id' => $task->alsoasked_search_id,
                'attempts' => $attempts + 1,
                'max_attempts' => self::ALSOASKED_NULL_RETRY_MAX,
            ]);
            if ($attempts + 1 >= self::ALSOASKED_NULL_RETRY_MAX) {
                $opts = $task->options ?? [];
                unset($opts['_alsoasked_null_results']);
                $task->update([
                    'alsoasked_questions' => [],
                    'options' => $opts,
                ]);
                \Illuminate\Support\Facades\Log::error('FAQ task AlsoAsked max null retries reached; marking empty', [
                    'task_id' => $task->id,
                    'alsoasked_search_id' => $task->alsoasked_search_id,
                ]);

                return true;
            }
            $opts = $task->options ?? [];
            $opts['_alsoasked_null_results'] = $attempts + 1;
            $task->update(['options' => $opts]);
            dispatch(new self($this->taskId))->delay(now()->addSeconds(5));

            return false;
        }

        $status = $searchResults['status'] ?? 'unknown';
        \Illuminate\Support\Facades\Log::info('FAQ task AlsoAsked poll status', [
            'task_id' => $task->id,
            'alsoasked_search_id' => $task->alsoasked_search_id,
            'status' => $status,
        ]);

        if ($status === 'running') {
            $opts = $task->options ?? [];
            unset($opts['_alsoasked_null_results']);
            $task->update(['options' => $opts]);
            dispatch(new self($this->taskId))->delay(now()->addSeconds(5));

            return false;
        }

        $opts = $task->options ?? [];
        unset($opts['_alsoasked_null_results']);

        if ($status === 'success' || $status === 'no_records') {
            $paaQuestions = [];
            if ($status === 'success') {
                $paaQuestions = $alsoAskedService->extractQuestions($searchResults);
                $serpCount = count($task->serp_questions ?? []);
                $maxPaa = max(0, 10 - $serpCount);
                if ($maxPaa === 0) {
                    $paaQuestions = [];
                } elseif (count($paaQuestions) > $maxPaa) {
                    $paaQuestions = array_slice($paaQuestions, 0, $maxPaa);
                }
            }
            $task->update([
                'alsoasked_questions' => $paaQuestions,
                'options' => $opts,
            ]);
            \Illuminate\Support\Facades\Log::info('FAQ task AlsoAsked resolved', [
                'task_id' => $task->id,
                'alsoasked_search_id' => $task->alsoasked_search_id,
                'status' => $status,
                'paa_questions_count' => count($paaQuestions),
            ]);

            return true;
        }

        $task->update([
            'alsoasked_questions' => [],
            'options' => $opts,
        ]);
        \Illuminate\Support\Facades\Log::warning('FAQ task AlsoAsked resolved with unsupported status', [
            'task_id' => $task->id,
            'alsoasked_search_id' => $task->alsoasked_search_id,
            'status' => $status,
        ]);

        return true;
    }

    /**
     * Strip job-only option keys so FAQ source hash / stored options stay stable.
     *
     * @return array<string, mixed>
     */
    protected function filterInternalTaskOptions(array $options): array
    {
        return array_filter(
            $options,
            static fn (int|string $k): bool => ! str_starts_with((string) $k, '_'),
            ARRAY_FILTER_USE_KEY
        );
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

        // Strictly limit SERP questions to max 10
        if (count($serpQuestions) > 10) {
            $serpQuestions = array_slice($serpQuestions, 0, 10);
        }

        $languageCode = $task->options['language_code'] ?? config('services.faq.default_language', 'en');
        $locationCode = $task->options['location_code'] ?? config('services.faq.default_location', 2840);

        // Get top keywords if not already available
        $topKeywords = [];
        if (! empty($task->question_keywords) && is_array($task->question_keywords)) {
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

                if (! empty($topKeywords)) {
                    $currentKeywords = $task->question_keywords ?? [];
                    $currentKeywords['top_keywords'] = $topKeywords;
                    $task->update(['question_keywords' => $currentKeywords]);
                }
            } catch (\Exception $e) {
                // Continue without keywords
            }
        }

        try {
            // Generate answers for SERP questions (strictly limited to 10)
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
            \Illuminate\Support\Facades\Log::error('Failed to generate SERP answers: '.$e->getMessage());
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

        // Strictly limit PAA questions so total (SERP + PAA) = 10 max
        $serpCount = count($task->serp_questions ?? []);
        $paaCount = count($paaQuestions);
        $maxPaaQuestions = max(0, 10 - $serpCount);

        if ($maxPaaQuestions === 0) {
            // SERP already has 10 questions, don't process any PAA
            return;
        }

        if ($paaCount > $maxPaaQuestions) {
            // Limit PAA questions to fit within 10 total
            $paaQuestions = array_slice($paaQuestions, 0, $maxPaaQuestions);
        }

        $languageCode = $task->options['language_code'] ?? config('services.faq.default_language', 'en');
        $locationCode = $task->options['location_code'] ?? config('services.faq.default_location', 2840);

        // Get top keywords if not already available
        $topKeywords = [];
        if (! empty($task->question_keywords) && is_array($task->question_keywords)) {
            if (isset($task->question_keywords['top_keywords']) && is_array($task->question_keywords['top_keywords'])) {
                $topKeywords = $task->question_keywords['top_keywords'];
            }
        }

        try {
            // Generate answers for PAA questions (strictly limited to ensure total <= 10)
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
            \Illuminate\Support\Facades\Log::error('Failed to generate PAA answers: '.$e->getMessage());
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
        $publicOptions = $this->filterInternalTaskOptions($task->options ?? []);
        $sourceHash = $faqGeneratorService->getSourceHash($input, $publicOptions);

        // Check for existing FAQ
        $existingFaq = $faqGeneratorService->findExistingFaq($input, $publicOptions);
        if ($existingFaq && $task->isCompleted()) {
            $faqGeneratorService->incrementApiCallsSaved($existingFaq->id);
            $existingFaq->refresh();

            return;
        }

        // Combine SERP and PAA answers
        $allFaqs = [];

        // Add SERP answers with source indicator
        if (! empty($task->serp_answers) && is_array($task->serp_answers)) {
            foreach ($task->serp_answers as $faq) {
                $faq['source'] = 'serp';
                $allFaqs[] = $faq;
            }
        }

        // Add PAA answers with source indicator
        if (! empty($task->paa_answers) && is_array($task->paa_answers)) {
            foreach ($task->paa_answers as $faq) {
                $faq['source'] = 'paa';
                $allFaqs[] = $faq;
            }
        }

        if (empty($allFaqs)) {
            if (! $allowPartial) {
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
                $this->recordFaqUsage($task);

                return;
            }
        }

        // Store combined FAQs in database
        $faqRecord = $faqGeneratorService->storeFaqsInDatabase(
            $task->url,
            $task->topic,
            $allFaqs,
            $publicOptions,
            $sourceHash
        );

        if (! $faqRecord || ! $faqRecord->id) {
            throw new \RuntimeException('Failed to store FAQs: Invalid FAQ record returned');
        }

        $task->markAsCompleted($faqRecord->id);
        $this->recordFaqUsage($task);
    }

    protected function recordFaqUsage(FaqTask $task): void
    {
        if ($task->credit_reservation_id) {
            app(\App\Domain\Billing\Contracts\WalletServiceInterface::class)
                ->completeReservation($task->credit_reservation_id);

            return;
        }
        $user = $task->user;
        if ($user) {
            app(\App\Domain\Billing\Services\CreditConsumptionService::class)
                ->recordUsage($user, 'faq_generator');
        }
    }
}

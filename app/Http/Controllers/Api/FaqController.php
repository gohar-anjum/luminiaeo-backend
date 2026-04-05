<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FaqGenerationRequest;
use App\Services\ApiResponseModifier;
use App\Services\FAQ\FaqGeneratorService;
use App\Support\ReservationCompletion;
use App\Traits\ValidatesResourceOwnership;
use Illuminate\Http\JsonResponse;

class FaqController extends Controller
{
    use ValidatesResourceOwnership;

    public function __construct(
        protected FaqGeneratorService $faqService,
        protected ApiResponseModifier $responseModifier,
    ) {}

    public function generate(FaqGenerationRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $responseDTO = ReservationCompletion::run($request, function () use ($validated) {
                return $this->faqService->generateFaqs(
                    $validated['input'],
                    $validated['options'] ?? []
                );
            });

            return $this->responseModifier
                ->setData($responseDTO->toArray())
                ->setMessage($responseDTO->fromDatabase ? 'FAQs retrieved from database' : 'FAQs generated successfully')
                ->response();

        } catch (\InvalidArgumentException $e) {
            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(422)
                ->response();

        } catch (\RuntimeException $e) {
            return $this->responseModifier
                ->setMessage('Failed to generate FAQs: '.$e->getMessage())
                ->setResponseCode(500)
                ->response();

        } catch (\Exception $e) {
            return $this->responseModifier
                ->setMessage('An unexpected error occurred while generating FAQs')
                ->setResponseCode(500)
                ->response();
        }
    }

    public function createTask(FaqGenerationRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $reservation = $request->attributes->get('credit_reservation');
            $creditReservationId = $reservation['transaction_id'] ?? null;

            $task = $this->faqService->createFaqTask(
                $validated['input'],
                $validated['options'] ?? [],
                $creditReservationId
            );

            return $this->responseModifier
                ->setData([
                    'task_id' => $task->task_id,
                    'status' => $task->status,
                    'progress' => 5,
                ])
                ->setMessage('FAQ generation started')
                ->response();

        } catch (\InvalidArgumentException $e) {
            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(422)
                ->response();

        } catch (\RuntimeException $e) {
            return $this->responseModifier
                ->setMessage('Failed to create FAQ task: '.$e->getMessage())
                ->setResponseCode(500)
                ->response();

        } catch (\Exception $e) {
            return $this->responseModifier
                ->setMessage('An unexpected error occurred while creating FAQ task')
                ->setResponseCode(500)
                ->response();
        }
    }

    public function getTaskStatus(string $taskId): JsonResponse
    {
        try {
            $task = \App\Models\FaqTask::where('task_id', $taskId)->first();

            if (! $task) {
                return $this->responseModifier
                    ->setMessage('Task not found')
                    ->setResponseCode(404)
                    ->response();
            }

            $this->validateTaskOwnership($task);

            // Get questions and answers from both sources
            $serpQuestions = $task->serp_questions ?? [];
            $serpAnswers = $task->serp_answers ?? [];
            $paaQuestions = $task->alsoasked_questions ?? [];
            $paaAnswers = $task->paa_answers ?? [];
            $questionKeywords = $task->question_keywords ?? [];
            $topicKeywords = [];
            if (isset($questionKeywords['top_keywords']) && is_array($questionKeywords['top_keywords'])) {
                $topicKeywords = array_values($questionKeywords['top_keywords']);
            }

            // STRICTLY limit SERP questions to 10 max
            if (count($serpQuestions) > 10) {
                $serpQuestions = array_slice($serpQuestions, 0, 10);
            }

            // STRICTLY limit PAA questions so total (SERP + PAA) = 10 max
            $serpCount = count($serpQuestions);
            $maxPaaQuestions = max(0, 10 - $serpCount);
            if (count($paaQuestions) > $maxPaaQuestions) {
                $paaQuestions = array_slice($paaQuestions, 0, $maxPaaQuestions);
            }

            // Build questions array with progressive answers
            $questions = [];

            // Add SERP questions with answers if available
            foreach ($serpQuestions as $question) {
                $questionData = [
                    'question' => $question,
                    'keywords' => [],
                    'source' => 'serp',
                    'has_answer' => false,
                    'answer' => null,
                ];

                // Check if answer exists in serp_answers
                foreach ($serpAnswers as $answerItem) {
                    if (isset($answerItem['question']) &&
                        strtolower(trim($answerItem['question'])) === strtolower(trim($question))) {
                        $questionData['has_answer'] = true;
                        $questionData['answer'] = $answerItem['answer'] ?? null;
                        $questionData['keywords'] = $this->mergeFaqQuestionKeywords(
                            $answerItem,
                            $question,
                            $questionKeywords
                        );
                        break;
                    }
                }

                if (empty($questionData['keywords'])) {
                    $questionData['keywords'] = $this->mergeFaqQuestionKeywords(
                        [],
                        $question,
                        $questionKeywords
                    );
                }

                $questions[] = $questionData;
            }

            // Add PAA questions with answers if available
            foreach ($paaQuestions as $question) {
                $questionData = [
                    'question' => $question,
                    'keywords' => [],
                    'source' => 'paa',
                    'has_answer' => false,
                    'answer' => null,
                ];

                // Check if answer exists in paa_answers
                foreach ($paaAnswers as $answerItem) {
                    if (isset($answerItem['question']) &&
                        strtolower(trim($answerItem['question'])) === strtolower(trim($question))) {
                        $questionData['has_answer'] = true;
                        $questionData['answer'] = $answerItem['answer'] ?? null;
                        $questionData['keywords'] = $this->mergeFaqQuestionKeywords(
                            $answerItem,
                            $question,
                            $questionKeywords
                        );
                        break;
                    }
                }

                if (empty($questionData['keywords'])) {
                    $questionData['keywords'] = $this->mergeFaqQuestionKeywords(
                        [],
                        $question,
                        $questionKeywords
                    );
                }

                $questions[] = $questionData;
            }

            // STRICT FINAL CHECK: Ensure total questions never exceeds 10
            if (count($questions) > 10) {
                $questions = array_slice($questions, 0, 10);
            }

            // If completed, also check final FAQ record for any missing answers
            if ($task->isCompleted() && $task->faq_id) {
                $faq = \App\Models\Faq::find($task->faq_id);
                if ($faq && ! empty($faq->faqs)) {
                    // Create a map of question -> answer
                    $answerMap = [];
                    foreach ($faq->faqs as $faqItem) {
                        if (isset($faqItem['question']) && isset($faqItem['answer'])) {
                            $qKey = strtolower(trim($faqItem['question']));
                            $answerMap[$qKey] = [
                                'answer' => $faqItem['answer'],
                                'keywords' => $faqItem['keywords'] ?? [],
                            ];
                        }
                    }

                    // Fill in any missing answers
                    foreach ($questions as &$questionData) {
                        if (! $questionData['has_answer']) {
                            $questionLower = strtolower(trim($questionData['question']));
                            if (isset($answerMap[$questionLower])) {
                                $questionData['has_answer'] = true;
                                $questionData['answer'] = $answerMap[$questionLower]['answer'];
                                if (! empty($answerMap[$questionLower]['keywords'])) {
                                    $questionData['keywords'] = $this->normalizeKeywordList(
                                        $answerMap[$questionLower]['keywords']
                                    );
                                }
                            }
                        }
                    }
                    unset($questionData);
                }
            }

            // Calculate progress percentage
            $progress = 0;
            if ($task->isCompleted()) {
                $progress = 100;
            } elseif ($task->isFailed()) {
                $progress = 0;
            } elseif ($task->isProcessing()) {
                $hasSerpAnswers = ! empty($serpAnswers);
                $hasPaaAnswers = ! empty($paaAnswers);
                $alsoAskedPending = ! empty($task->alsoasked_search_id)
                    && $task->alsoasked_questions === null;

                if ($hasSerpAnswers && $hasPaaAnswers) {
                    $progress = 100; // Both complete, just finalizing
                } elseif ($hasSerpAnswers && $alsoAskedPending) {
                    $progress = 40; // SERP done; AlsoAsked poll / PAA answers still in flight
                } elseif ($hasSerpAnswers) {
                    $progress = 55; // SERP done; AlsoAsked resolved, PAA or finalize pending
                } elseif (! empty($serpQuestions)) {
                    $progress = 30; // SERP questions available, generating answers
                } else {
                    $progress = 10; // Initial processing
                }
            } elseif ($task->isPending()) {
                $progress = 5; // Just started
            }

            // Calculate total from limited questions array (not from raw data)
            $totalQuestions = count($questions);
            $data = [
                'status' => $task->status,
                'progress' => $progress,
                'questions' => $questions,
                'total_questions' => $totalQuestions,
                'topic_keywords' => $topicKeywords,
            ];

            if ($task->isFailed()) {
                $data['error'] = $task->error_message ?? 'An error occurred while processing';
            }

            return $this->responseModifier
                ->setData($data)
                ->setMessage('Task status retrieved successfully')
                ->response();

        } catch (\Exception $e) {
            return $this->responseModifier
                ->setMessage('Failed to get task status')
                ->setResponseCode(500)
                ->response();
        }
    }

    /**
     * @param  array<string, mixed>  $answerItem
     * @param  array<string, mixed>  $questionKeywords
     * @return list<string>
     */
    protected function mergeFaqQuestionKeywords(array $answerItem, string $question, array $questionKeywords): array
    {
        $fromAnswer = [];
        if (! empty($answerItem['keywords']) && is_array($answerItem['keywords'])) {
            $fromAnswer = $answerItem['keywords'];
        } elseif (! empty($answerItem['used_keywords']) && is_array($answerItem['used_keywords'])) {
            $fromAnswer = $answerItem['used_keywords'];
        }

        $fromMap = [];
        if (isset($questionKeywords[$question]) && is_array($questionKeywords[$question])) {
            $fromMap = $questionKeywords[$question];
        }

        return $this->normalizeKeywordList(array_merge($fromAnswer, $fromMap));
    }

    /**
     * @param  array<int, mixed>  $keywords
     * @return list<string>
     */
    protected function normalizeKeywordList(array $keywords): array
    {
        $out = [];
        foreach ($keywords as $k) {
            if (is_string($k) && trim($k) !== '') {
                $out[] = trim($k);
            }
        }

        return array_values(array_unique($out));
    }
}

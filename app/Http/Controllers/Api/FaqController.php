<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FaqGenerationRequest;
use App\Services\ApiResponseModifier;
use App\Services\FAQ\FaqGeneratorService;
use App\Traits\ValidatesResourceOwnership;
use Illuminate\Http\JsonResponse;

class FaqController extends Controller
{
    use ValidatesResourceOwnership;
    protected FaqGeneratorService $faqService;
    protected ApiResponseModifier $responseModifier;

    public function __construct(FaqGeneratorService $faqService, ApiResponseModifier $responseModifier)
    {
        $this->faqService = $faqService;
        $this->responseModifier = $responseModifier;
    }

    public function generate(FaqGenerationRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $responseDTO = $this->faqService->generateFaqs(
                $validated['input'],
                $validated['options'] ?? []
            );

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
                ->setMessage('Failed to generate FAQs: ' . $e->getMessage())
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

            $task = $this->faqService->createFaqTask(
                $validated['input'],
                $validated['options'] ?? []
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
                ->setMessage('Failed to create FAQ task: ' . $e->getMessage())
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

            if (!$task) {
                return $this->responseModifier
                    ->setMessage('Task not found')
                    ->setResponseCode(404)
                    ->response();
            }

            $this->validateTaskOwnership($task);

            // Get all questions (combined from SERP and AlsoAsked)
            $allQuestions = $task->serp_questions ?? [];
            $questionKeywords = $task->question_keywords ?? [];

            // Build questions array with keywords
            $questions = [];
            foreach ($allQuestions as $question) {
                $questionData = [
                    'question' => $question,
                    'keywords' => $questionKeywords[$question] ?? [],
                    'has_answer' => false,
                    'answer' => null,
                ];
                $questions[] = $questionData;
            }

            // If completed, merge answers with questions
            if ($task->isCompleted() && $task->faq_id) {
                $faq = \App\Models\Faq::find($task->faq_id);
                if ($faq && !empty($faq->faqs)) {
                    // Create a map of question -> answer
                    $answerMap = [];
                    foreach ($faq->faqs as $faqItem) {
                        if (isset($faqItem['question']) && isset($faqItem['answer'])) {
                            $answerMap[strtolower(trim($faqItem['question']))] = $faqItem['answer'];
                        }
                    }

                    // Match answers to questions
                    foreach ($questions as &$questionData) {
                        $questionLower = strtolower(trim($questionData['question']));
                        if (isset($answerMap[$questionLower])) {
                            $questionData['has_answer'] = true;
                            $questionData['answer'] = $answerMap[$questionLower];
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
                // If questions are available, we're at least 30% done
                // If keywords are available, we're at least 60% done
                // If processing, we're between 60-90%
                if (!empty($allQuestions)) {
                    if (!empty($questionKeywords)) {
                        $progress = 70; // Keywords generated, generating answers
                    } else {
                        $progress = 40; // Questions available, generating keywords
                    }
                } else {
                    $progress = 10; // Initial processing
                }
            } elseif ($task->isPending()) {
                $progress = 5; // Just started
            }

            $data = [
                'status' => $task->status,
                'progress' => $progress,
                'questions' => $questions,
                'total_questions' => count($allQuestions),
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
}

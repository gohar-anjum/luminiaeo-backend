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

            // Get questions and answers from both sources
            $serpQuestions = $task->serp_questions ?? [];
            $serpAnswers = $task->serp_answers ?? [];
            $paaQuestions = $task->alsoasked_questions ?? [];
            $paaAnswers = $task->paa_answers ?? [];
            $questionKeywords = $task->question_keywords ?? [];

            // Build questions array with progressive answers
            $questions = [];
            
            // Add SERP questions with answers if available
            foreach ($serpQuestions as $question) {
                $questionData = [
                    'question' => $question,
                    'keywords' => $questionKeywords[$question] ?? [],
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
                        break;
                    }
                }
                
                $questions[] = $questionData;
            }
            
            // Add PAA questions with answers if available
            foreach ($paaQuestions as $question) {
                $questionData = [
                    'question' => $question,
                    'keywords' => $questionKeywords[$question] ?? [],
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
                        break;
                    }
                }
                
                $questions[] = $questionData;
            }

            // If completed, also check final FAQ record for any missing answers
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

                    // Fill in any missing answers
                    foreach ($questions as &$questionData) {
                        if (!$questionData['has_answer']) {
                            $questionLower = strtolower(trim($questionData['question']));
                            if (isset($answerMap[$questionLower])) {
                                $questionData['has_answer'] = true;
                                $questionData['answer'] = $answerMap[$questionLower];
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
                $hasSerpAnswers = !empty($serpAnswers);
                $hasPaaAnswers = !empty($paaAnswers);
                
                if ($hasSerpAnswers && $hasPaaAnswers) {
                    $progress = 100; // Both complete, just finalizing
                } elseif ($hasSerpAnswers) {
                    $progress = 50; // SERP answers ready, waiting for PAA
                } elseif (!empty($serpQuestions)) {
                    $progress = 30; // SERP questions available, generating answers
                } else {
                    $progress = 10; // Initial processing
                }
            } elseif ($task->isPending()) {
                $progress = 5; // Just started
            }

            $totalQuestions = count($serpQuestions) + count($paaQuestions);
            $data = [
                'status' => $task->status,
                'progress' => $progress,
                'questions' => $questions,
                'total_questions' => $totalQuestions,
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

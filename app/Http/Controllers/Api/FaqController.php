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
                    'created_at' => $task->created_at->toIso8601String(),
                ])
                ->setMessage('FAQ task created successfully')
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

            $data = [
                'task_id' => $task->task_id,
                'status' => $task->status,
                'created_at' => $task->created_at->toIso8601String(),
                'updated_at' => $task->updated_at->toIso8601String(),
            ];

            if ($task->isCompleted() && $task->faq_id) {
                $faq = \App\Models\Faq::find($task->faq_id);
                if ($faq) {
                    $data['faqs'] = $faq->faqs;
                    $data['faqs_count'] = count($faq->faqs);
                    $data['completed_at'] = $task->completed_at?->toIso8601String();
                }
            }

            if ($task->isFailed()) {
                $data['error_message'] = $task->error_message;
                $data['completed_at'] = $task->completed_at?->toIso8601String();
            }

            if ($task->isProcessing() || $task->isPending()) {
                $data['serp_questions_count'] = count($task->serp_questions ?? []);
                $data['alsoasked_search_id'] = $task->alsoasked_search_id;
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

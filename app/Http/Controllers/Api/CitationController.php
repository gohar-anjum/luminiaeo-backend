<?php

namespace App\Http\Controllers\Api;

use App\DTOs\CitationRequestDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\CitationAnalyzeRequest;
use App\Interfaces\CitationRepositoryInterface;
use App\Models\CitationTask;
use App\Services\ApiResponseModifier;
use App\Services\CitationService;
use App\Traits\HasApiResponse;
use App\Traits\ValidatesResourceOwnership;
use Illuminate\Http\Request;

class CitationController extends Controller
{
    use HasApiResponse, ValidatesResourceOwnership;

    public function __construct(
        protected CitationService $citationService,
        protected CitationRepositoryInterface $citationRepository,
        protected ApiResponseModifier $responseModifier,
    ) {
    }

    public function analyze(CitationAnalyzeRequest $request)
    {
        $validated = $request->validated();

        $dto = CitationRequestDTO::fromArray($validated, config('citations.default_queries', 1000));

        // If LLM Mentions API is enabled, use the separate LLM Mentions flow
        if (config('citations.dataforseo.llm_mentions_enabled', false)) {
            $task = $this->citationService->createLLMMentionsTask($dto);

            // LLM Mentions returns immediately, so return 200 instead of 202
            $message = $task->status === CitationTask::STATUS_COMPLETED
                ? 'LLM Mentions data retrieved successfully.'
                : 'LLM Mentions task created. ';

            return $this->responseModifier
                ->setData([
                    'task_id' => $task->id,
                    'status' => $task->status,
                    'status_url' => route('citations.status', $task->id),
                    'results_url' => route('citations.results', $task->id),
                ])
                ->setMessage($message)
                ->setResponseCode($task->status === CitationTask::STATUS_COMPLETED ? 200 : 202)
                ->response();
        }

        // Use the standard citation flow (SERP API)
        $task = $this->citationService->createTask($dto);

        return $this->responseModifier
            ->setData([
                'task_id' => $task->id,
                'status' => $task->status,
                'status_url' => route('citations.status', $task->id),
                'results_url' => route('citations.results', $task->id),
            ])
            ->setMessage('Queries generated and citation checks are queued. Poll ' . route('citations.status', $task->id) . ' for progress, then use ' . route('citations.results', $task->id) . ' when completed.')
            ->setResponseCode(202)
            ->response();
    }

    public function status(int $taskId)
    {
        $task = $this->citationRepository->find($taskId);
        if (!$task) {
            return $this->responseModifier
                ->setMessage('Task not found')
                ->setResponseCode(404)
                ->response();
        }

        $this->validateTaskOwnership($task);

        return $this->responseModifier
            ->setData([
                'task_id' => $task->id,
                'status' => $task->status,
                'progress' => $task->results['progress'] ?? null,
                'competitors' => $task->competitors,
                'meta' => $task->meta,
            ])
            ->setMessage('Task status retrieved successfully')
            ->response();
    }

    public function results(int $taskId)
    {
        $task = $this->citationRepository->find($taskId);
        if (!$task) {
            return $this->responseModifier
                ->setMessage('Task not found')
                ->setResponseCode(404)
                ->response();
        }

        $this->validateTaskOwnership($task);

        $results = $task->results ?? [];
        $byQuery = $results['by_query'] ?? [];

        $cleanedByQuery = [];
        foreach ($byQuery as $index => $entry) {
            $cleaned = [
                'query' => $entry['query'] ?? '',
            ];

            foreach (['gpt', 'gemini'] as $provider) {
                if (isset($entry[$provider])) {
                    $cleaned[$provider] = [
                        'citation_found' => $entry[$provider]['citation_found'] ?? false,
                        'confidence' => $entry[$provider]['confidence'] ?? 0,
                        'citation_references' => $entry[$provider]['citation_references'] ?? [],
                    ];
                }
            }

            if (isset($entry['top_competitors'])) {
                $cleaned['top_competitors'] = $entry['top_competitors'];
            }

            $cleanedByQuery[$index] = $cleaned;
        }

        $scores = [
            'gpt_score' => $task->meta['gpt_score'] ?? null,
            'gemini_score' => $task->meta['gemini_score'] ?? null,
        ];

        return $this->responseModifier
            ->setData([
                'task_id' => $task->id,
                'url' => $task->url,
                'status' => $task->status,
                'queries' => $task->queries ?? [],
                'results' => [
                    'by_query' => $cleanedByQuery,
                    'scores' => $scores,
                ],
                'competitors' => $task->competitors ?? [],
                'meta' => $task->meta ?? [],
            ])
            ->setMessage('Citation results retrieved successfully')
            ->response();
    }

    public function retry(int $taskId)
    {
        $task = $this->citationRepository->find($taskId);
        if (!$task) {
            return $this->responseModifier
                ->setMessage('Task not found')
                ->setResponseCode(404)
                ->response();
        }

        $this->validateTaskOwnership($task);

        $queries = $task->queries ?? [];
        $byQuery = $task->results['by_query'] ?? [];
        $missing = [];

        foreach ($queries as $index => $query) {
            if (!array_key_exists((string) $index, $byQuery)) {
                $missing[$index] = $query;
            }
        }

        if (empty($missing)) {
            return $this->responseModifier
                ->setMessage('No missing queries to retry')
                ->setResponseCode(400)
                ->response();
        }

        $this->citationService->dispatchPartialChunks($task, $missing);

        return $this->responseModifier
            ->setData([
                'task_id' => $task->id,
                'missing_count' => count($missing),
            ])
            ->setMessage('Retry dispatched successfully')
            ->response();
    }
}

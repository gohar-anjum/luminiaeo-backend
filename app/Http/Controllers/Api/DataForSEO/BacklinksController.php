<?php

namespace App\Http\Controllers\Api\DataForSEO;

use App\Http\Controllers\Controller;
use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Jobs\FetchBacklinksResultsJob;
use App\Services\ApiResponseModifier;
use Illuminate\Http\Request;

class BacklinksController extends Controller
{
    protected BacklinksRepositoryInterface $repository;
    protected ApiResponseModifier $responseModifier;

    public function __construct(BacklinksRepositoryInterface $repository, ApiResponseModifier $responseModifier)
    {
        $this->repository = $repository;
        $this->responseModifier = $responseModifier;
    }

    /**
     * Submit backlinks retrieval task
     */
    public function submit(Request $request)
    {
        $request->validate([
            'domain' => 'required|url',
            'limit' => 'sometimes|integer|min:1|max:1000',
        ]);

        $task = $this->repository->createTask(
            $request->domain,
            $request->limit ?? 100
        );
        if (isset($task['id'])) {
            FetchBacklinksResultsJob::dispatch($task['id'], $request->domain)->delay(now()->addMinute());
        }

        return $this->responseModifier->setData(['task' => $task])->response();
    }

    /**
     * Retrieve backlinks results
     */
    public function results(Request $request)
    {
        $request->validate([
            'task_id' => 'required|string',
        ]);

        $results = $this->repository->fetchResults($request->task_id);
        return $this->responseModifier->setData($results)->response();
    }
}

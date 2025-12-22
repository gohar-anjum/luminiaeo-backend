<?php

namespace App\Http\Controllers\Api;

use App\DTOs\KeywordResearchRequestDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\KeywordResearchRequest;
use App\Services\KeywordService;
use App\Traits\HasApiResponse;
use Illuminate\Http\Request;

class KeywordResearchController extends Controller
{
    use HasApiResponse;

    protected KeywordService $keywordService;

    public function __construct(KeywordService $keywordService)
    {
        $this->keywordService = $keywordService;
    }

    public function create(KeywordResearchRequest $request)
    {
        $validated = $request->validated();
        $dto = KeywordResearchRequestDTO::fromArray($validated);
        $job = $this->keywordService->createKeywordResearch($dto);

        return response()->json([
            'status' => 'success',
            'message' => 'Keyword research job created successfully',
            'data' => [
                'id' => $job->id,
                'query' => $job->query,
                'status' => $job->status,
                'created_at' => $job->created_at,
            ],
        ], 201);
    }

    public function status($id)
    {
        return $this->keywordService->getKeywordResearchStatus($id);
    }

    public function results($id)
    {
        return $this->keywordService->getKeywordResearchResults($id);
    }

    public function index()
    {
        return $this->keywordService->listKeywordResearchJobs();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\DTOs\KeywordResearchRequestDTO;
use App\Http\Controllers\Controller;
use App\Services\KeywordService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KeywordResearchController extends Controller
{
    protected KeywordService $keywordService;

    public function __construct(KeywordService $keywordService)
    {
        $this->keywordService = $keywordService;
    }

    public function create(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|max:255',
            'project_id' => 'nullable|integer|exists:projects,id',
            'language_code' => 'nullable|string|max:10',
            'geo_target_id' => 'nullable|integer',
            'max_keywords' => 'nullable|integer|min:1|max:5000',
            'enable_google_planner' => 'nullable|boolean',
            'enable_scraper' => 'nullable|boolean',
            'enable_answerthepublic' => 'nullable|boolean',
            'enable_clustering' => 'nullable|boolean',
            'enable_intent_scoring' => 'nullable|boolean',
        ]);

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


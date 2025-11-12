<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Google\KeywordPlannerService;
use Illuminate\Http\Request;

class KeywordPlannerController extends Controller
{
    private KeywordPlannerService $keywordPlannerService;

    public function __construct(KeywordPlannerService $keywordPlannerService)
    {
        $this->keywordPlannerService = $keywordPlannerService;
    }

    public function getKeywordIdeas(Request $request)
    {
        $request->validate(['keyword' => 'required|string']);
        $ideas = $this->keywordPlannerService->getKeywordIdeas($request->keyword);

        return response()->json([
            'status' => 'success',
            'count' => count($ideas),
            'data' => $ideas,
        ]);
    }
}

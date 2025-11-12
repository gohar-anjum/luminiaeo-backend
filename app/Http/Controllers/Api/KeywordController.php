<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KeywordService;
use Illuminate\Http\Request;

class KeywordController extends Controller
{
    protected KeywordService $keywordService;

    public function __construct(KeywordService $keywordService)
    {
        $this->keywordService = $keywordService;
    }

    public function index()
    {
        return $this->keywordService->getAllKeywords();
    }

    public function store(Request $request)
    {
        return $this->keywordService->createKeyword($request->all());
    }

    public function update(Request $request, $id)
    {
        return $this->keywordService->updateKeyword($id, $request->all());
    }

    public function destroy($id)
    {
        return $this->keywordService->deleteKeyword($id);
    }
}

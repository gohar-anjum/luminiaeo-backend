<?php

namespace App\Http\Controllers\Api\PageAnalysis;

use App\Http\Controllers\Controller;
use App\Http\Requests\PageAnalysis\ContentOutlineRequest;
use App\Services\ApiResponseModifier;
use App\Services\PageAnalysis\ContentOutlineService;
use App\Support\ReservationCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ContentOutlineController extends Controller
{
    public function __construct(
        protected ContentOutlineService $contentOutlineService,
        protected ApiResponseModifier $responseModifier,
    ) {}

    public function generate(ContentOutlineRequest $request): JsonResponse
    {
        try {
            $result = ReservationCompletion::runWithCondition(
                $request,
                fn () => $this->contentOutlineService->generate(
                    $request->input('keyword'),
                    $request->input('tone', 'professional'),
                ),
                fn ($r) => !($r['from_cache'] ?? false)
            );

            return $this->responseModifier
                ->setData($result)
                ->setMessage($result['from_cache'] ?? false ? 'Content outline from cache' : 'Content outline generated successfully')
                ->response();
        } catch (\RuntimeException $e) {
            Log::error('Content outline generation failed', ['error' => $e->getMessage()]);
            return $this->responseModifier
                ->setMessage('Failed to generate content outline. AI providers unavailable.')
                ->setResponseCode(503)
                ->response();
        } catch (\Exception $e) {
            Log::error('Unexpected error in content outline', ['error' => $e->getMessage()]);
            return $this->responseModifier
                ->setMessage('Failed to generate content outline')
                ->setResponseCode(500)
                ->response();
        }
    }

    public function history(Request $request): JsonResponse
    {
        $outlines = \App\Models\ContentOutline::where('user_id', $request->user()->id)
            ->orderByDesc('generated_at')
            ->paginate($request->integer('per_page', 20));

        return $this->responseModifier
            ->setData($outlines)
            ->setMessage('Content outline history retrieved')
            ->response();
    }
}

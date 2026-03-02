<?php

namespace App\Http\Controllers\Api\PageAnalysis;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseModifier;
use App\Services\PageAnalysis\SemanticScoreService;
use App\Support\ReservationCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SemanticScoreController extends Controller
{
    public function __construct(
        protected SemanticScoreService $semanticScoreService,
        protected ApiResponseModifier $responseModifier,
    ) {}

    /**
     * Evaluate how well a page semantically covers its primary topic.
     * POST /api/page-analysis/semantic-score
     * Body: { "url": "https://..." }
     */
    public function evaluate(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        try {
            $score = ReservationCompletion::run($request, fn () => $this->semanticScoreService->evaluate(
                $request->input('url'),
            ));

            return $this->responseModifier
                ->setData(['semantic_score' => $score])
                ->setMessage('Semantic score computed')
                ->response();
        } catch (\Illuminate\Http\Client\RequestException $e) {
            return $this->responseModifier
                ->setMessage('Page analysis service unavailable: ' . $e->getMessage())
                ->setResponseCode(503)
                ->response();
        } catch (\InvalidArgumentException $e) {
            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(422)
                ->response();
        } catch (\Exception $e) {
            return $this->responseModifier
                ->setMessage('Failed to compute semantic score')
                ->setResponseCode(500)
                ->response();
        }
    }

    /**
     * Get semantic analysis history for the authenticated user.
     */
    public function history(Request $request): JsonResponse
    {
        $analyses = \App\Models\SemanticAnalysis::where('user_id', $request->user()->id)
            ->orderByDesc('analyzed_at')
            ->limit(50)
            ->get();

        return $this->responseModifier
            ->setData(['analyses' => $analyses])
            ->setMessage('Semantic analysis history retrieved')
            ->response();
    }
}

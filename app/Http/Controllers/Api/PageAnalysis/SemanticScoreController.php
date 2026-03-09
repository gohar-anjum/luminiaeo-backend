<?php

namespace App\Http\Controllers\Api\PageAnalysis;

use App\Http\Controllers\Controller;
use App\Http\Requests\PageAnalysis\SemanticScoreRequest;
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

    public function evaluate(SemanticScoreRequest $request): JsonResponse
    {
        try {
            $result = ReservationCompletion::runWithCondition(
                $request,
                fn () => $this->semanticScoreService->evaluate(
                    $request->input('url'),
                    $request->input('keyword'),
                ),
                fn ($r) => !($r['from_cache'] ?? false)
            );

            return $this->responseModifier
                ->setData($result)
                ->setMessage($result['from_cache'] ?? false ? 'Semantic score from cache' : 'Semantic score computed')
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

    public function history(Request $request): JsonResponse
    {
        $analyses = \App\Models\SemanticAnalysis::where('user_id', $request->user()->id)
            ->orderByDesc('analyzed_at')
            ->paginate($request->integer('per_page', 20));

        return $this->responseModifier
            ->setData($analyses)
            ->setMessage('Semantic analysis history retrieved')
            ->response();
    }
}

<?php

namespace App\Http\Controllers\Api\PageAnalysis;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseModifier;
use App\Services\PageAnalysis\MetaOptimizerService;
use App\Support\ReservationCompletion;
use App\Exceptions\PageAnalysisException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaOptimizerController extends Controller
{
    public function __construct(
        protected MetaOptimizerService $metaOptimizer,
        protected ApiResponseModifier $responseModifier,
    ) {}

    /**
     * Optimize meta tags for a URL.
     * POST /api/page-analysis/meta-optimize
     * Body: { "url": "https://example.com" }
     */
    public function optimize(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        try {
            $result = ReservationCompletion::runWithCondition(
                $request,
                fn () => $this->metaOptimizer->handle($request->input('url')),
                fn ($r) => !($r['from_cache'] ?? false)
            );

            return $this->responseModifier
                ->setData($result)
                ->setMessage($result['from_cache'] ?? false ? 'Meta analysis from cache' : 'Meta tags optimized successfully')
                ->response();
        } catch (PageAnalysisException $e) {
            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode($e->getCode() ?: 503)
                ->response();
        } catch (\InvalidArgumentException $e) {
            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(422)
                ->response();
        } catch (\Exception $e) {
            return $this->responseModifier
                ->setMessage('Failed to optimize meta tags')
                ->setResponseCode(500)
                ->response();
        }
    }

    /**
     * Get meta analysis history for the authenticated user.
     */
    public function history(Request $request): JsonResponse
    {
        $analyses = \App\Models\MetaAnalysis::where('user_id', $request->user()->id)
            ->orderByDesc('analyzed_at')
            ->limit(50)
            ->get();

        return $this->responseModifier
            ->setData(['analyses' => $analyses])
            ->setMessage('Meta analysis history retrieved')
            ->response();
    }
}

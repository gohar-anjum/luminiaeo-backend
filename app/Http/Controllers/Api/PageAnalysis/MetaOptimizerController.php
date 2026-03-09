<?php

namespace App\Http\Controllers\Api\PageAnalysis;

use App\Http\Controllers\Controller;
use App\Http\Requests\PageAnalysis\MetaOptimizeRequest;
use App\Services\ApiResponseModifier;
use App\Services\PageAnalysis\MetaOptimizerService;
use App\Support\ReservationCompletion;
use App\Exceptions\PageAnalysisException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MetaOptimizerController extends Controller
{
    public function __construct(
        protected MetaOptimizerService $metaOptimizer,
        protected ApiResponseModifier $responseModifier,
    ) {}

    public function optimize(MetaOptimizeRequest $request): JsonResponse
    {
        try {
            $result = ReservationCompletion::runWithCondition(
                $request,
                fn () => $this->metaOptimizer->handle(
                    $request->input('url'),
                    $request->input('keyword'),
                ),
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
            Log::info($e->getMessage());
            return $this->responseModifier
                ->setMessage('Failed to optimize meta tags')
                ->setResponseCode(500)
                ->response();
        }
    }

    public function history(Request $request): JsonResponse
    {
        $analyses = \App\Models\MetaAnalysis::where('user_id', $request->user()->id)
            ->orderByDesc('analyzed_at')
            ->paginate($request->integer('per_page', 20));

        return $this->responseModifier
            ->setData($analyses)
            ->setMessage('Meta analysis history retrieved')
            ->response();
    }
}

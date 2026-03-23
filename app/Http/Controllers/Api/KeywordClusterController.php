<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Contracts\FeaturePricingServiceInterface;
use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\KeywordClusterRequest;
use App\Models\ClusterJob;
use App\Models\KeywordClusterSnapshot;
use App\Models\UserKeywordClusterAccess;
use App\Services\ApiResponseModifier;
use App\Services\Keyword\KeywordClusterEngineService;
use App\Traits\HasApiResponse;
use Illuminate\Http\Request;

class KeywordClusterController extends Controller
{
    use HasApiResponse;

    private const FEATURE_KEY = 'keyword_clustering';

    public function __construct(
        protected KeywordClusterEngineService $clusterEngineService,
        protected ApiResponseModifier $responseModifier,
        protected WalletServiceInterface $walletService,
        protected FeaturePricingServiceInterface $pricingService
    ) {}

    public function store(KeywordClusterRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();
        $keyword = $validated['keyword'];
        $languageCode = $validated['language_code'];
        $locationCode = $validated['location_code'];

        $normalized = $this->clusterEngineService->normalizeKeyword($keyword);
        $cacheKey = $this->clusterEngineService->cacheKey($normalized, $languageCode, $locationCode);

        if (UserKeywordClusterAccess::query()
            ->where('user_id', $user->id)
            ->where('cache_key', $cacheKey)
            ->exists()
        ) {
            $snapshot = KeywordClusterSnapshot::query()
                ->valid()
                ->forCacheKey($cacheKey)
                ->first();

            if ($snapshot) {
                return $this->responseModifier
                    ->setData([
                        'cache_hit' => true,
                        'charged' => false,
                        'snapshot_id' => $snapshot->id,
                        'expires_at' => $snapshot->expires_at,
                        'payload' => $snapshot->tree_json,
                    ])
                    ->setMessage('Keyword cluster tree loaded from cache (no charge)')
                    ->setResponseCode(200)
                    ->response();
            }

            UserKeywordClusterAccess::query()
                ->where('user_id', $user->id)
                ->where('cache_key', $cacheKey)
                ->delete();
        }

        $pendingJob = ClusterJob::query()
            ->where('user_id', $user->id)
            ->where('cache_key', $cacheKey)
            ->whereIn('status', [ClusterJob::STATUS_PENDING, ClusterJob::STATUS_PROCESSING])
            ->orderByDesc('id')
            ->first();

        if ($pendingJob) {
            return $this->responseModifier
                ->setData([
                    'cache_hit' => false,
                    'charged' => false,
                    'job_id' => $pendingJob->id,
                    'status' => $pendingJob->status,
                    'status_url' => route('keyword-clusters.status', ['id' => $pendingJob->id]),
                    'result_url' => route('keyword-clusters.result', ['id' => $pendingJob->id]),
                ])
                ->setMessage('Cluster job already in progress for this keyword.')
                ->setResponseCode(202)
                ->response();
        }

        try {
            $cost = $this->pricingService->getCreditCost(self::FEATURE_KEY);
        } catch (\Throwable $e) {
            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(400)
                ->response();
        }

        $requestId = uniqid('kc_', true);

        try {
            $reservation = $this->walletService->reserveCredits($user, self::FEATURE_KEY, $cost, [
                'reference_type' => 'feature_request',
                'reference_id' => $requestId,
                'metadata' => [
                    'route' => 'keyword-clusters.store',
                    'cache_key' => $cacheKey,
                ],
            ]);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'status' => 402,
                'message' => $e->getMessage(),
                'response' => ['credits_balance' => $this->walletService->getBalance($user)],
            ], 402);
        }

        $creditReservationId = $reservation->id;

        $result = $this->clusterEngineService->requestCluster(
            $keyword,
            $languageCode,
            $locationCode,
            $creditReservationId
        );

        if (! empty($result['duplicate_job'])) {
            $this->walletService->reverseReservation($creditReservationId);

            $job = $result['job'];
            if (! $job) {
                return $this->responseModifier
                    ->setMessage('Unable to resolve cluster job')
                    ->setResponseCode(500)
                    ->response();
            }

            return $this->responseModifier
                ->setData([
                    'cache_hit' => false,
                    'charged' => false,
                    'job_id' => $job->id,
                    'status' => $job->status,
                    'status_url' => route('keyword-clusters.status', ['id' => $job->id]),
                    'result_url' => route('keyword-clusters.result', ['id' => $job->id]),
                ])
                ->setMessage('Cluster job already in progress for this keyword.')
                ->setResponseCode(202)
                ->response();
        }

        if (! empty($result['lock_timeout'])) {
            $this->walletService->reverseReservation($creditReservationId);

            return $this->responseModifier
                ->setMessage('Cluster generation is busy for this keyword. Please retry shortly.')
                ->setResponseCode(503)
                ->response();
        }

        if ($result['hit'] && $result['snapshot']) {
            $this->walletService->completeReservation($creditReservationId);
            UserKeywordClusterAccess::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'cache_key' => $cacheKey,
                ]
            );

            $snap = $result['snapshot'];

            return $this->responseModifier
                ->setData([
                    'cache_hit' => true,
                    'charged' => true,
                    'snapshot_id' => $snap->id,
                    'expires_at' => $snap->expires_at,
                    'payload' => $snap->tree_json,
                ])
                ->setMessage('Keyword cluster tree loaded from cache')
                ->setResponseCode(200)
                ->response();
        }

        $job = $result['job'];
        if (! $job) {
            $this->walletService->reverseReservation($creditReservationId);

            return $this->responseModifier
                ->setMessage('Unable to start cluster job')
                ->setResponseCode(500)
                ->response();
        }

        return $this->responseModifier
            ->setData([
                'cache_hit' => false,
                'charged' => true,
                'job_id' => $job->id,
                'status' => $job->status,
                'status_url' => route('keyword-clusters.status', ['id' => $job->id]),
                'result_url' => route('keyword-clusters.result', ['id' => $job->id]),
            ])
            ->setMessage('Keyword cluster job queued. Poll status, then fetch the result when completed.')
            ->setResponseCode(202)
            ->response();
    }

    public function status(Request $request, int $id)
    {
        $job = ClusterJob::find($id);
        if (! $job || $job->user_id !== $request->user()->id) {
            return $this->responseModifier
                ->setMessage('Job not found')
                ->setResponseCode(404)
                ->response();
        }

        return $this->responseModifier
            ->setData([
                'job_id' => $job->id,
                'status' => $job->status,
                'snapshot_id' => $job->snapshot_id,
                'error_message' => $job->error_message,
                'started_at' => $job->started_at,
                'completed_at' => $job->completed_at,
            ])
            ->setMessage('Cluster job status')
            ->setResponseCode(200)
            ->response();
    }

    public function result(Request $request, int $id)
    {
        $job = ClusterJob::with('snapshot')->find($id);
        if (! $job || $job->user_id !== $request->user()->id) {
            return $this->responseModifier
                ->setMessage('Job not found')
                ->setResponseCode(404)
                ->response();
        }

        if ($job->status === ClusterJob::STATUS_FAILED) {
            return $this->responseModifier
                ->setData([
                    'job_id' => $job->id,
                    'status' => $job->status,
                    'error_message' => $job->error_message,
                ])
                ->setMessage('Cluster job failed')
                ->setResponseCode(422)
                ->response();
        }

        if ($job->status !== ClusterJob::STATUS_COMPLETED || ! $job->snapshot) {
            return $this->responseModifier
                ->setData([
                    'job_id' => $job->id,
                    'status' => $job->status,
                ])
                ->setMessage('Result not ready yet')
                ->setResponseCode(409)
                ->response();
        }

        $snap = $job->snapshot;

        return $this->responseModifier
            ->setData([
                'job_id' => $job->id,
                'snapshot_id' => $snap->id,
                'expires_at' => $snap->expires_at,
                'payload' => $snap->tree_json,
            ])
            ->setMessage('Keyword cluster tree ready')
            ->setResponseCode(200)
            ->response();
    }
}

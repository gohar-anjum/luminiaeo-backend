<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\DTOs\KeywordResearchRequestDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\KeywordResearchRequest;
use App\Services\ApiResponseModifier;
use App\Services\KeywordService;
use App\Traits\HasApiResponse;
use App\Traits\ValidatesResourceOwnership;
use Illuminate\Http\Request;

class KeywordResearchController extends Controller
{
    use HasApiResponse, ValidatesResourceOwnership;

    protected KeywordService $keywordService;
    protected ApiResponseModifier $responseModifier;

    public function __construct(KeywordService $keywordService, ApiResponseModifier $responseModifier)
    {
        $this->keywordService = $keywordService;
        $this->responseModifier = $responseModifier;
    }

    public function create(KeywordResearchRequest $request)
    {
        $validated = $request->validated();
        $dto = KeywordResearchRequestDTO::fromArray($validated);
        $reservation = $request->attributes->get('credit_reservation');
        $creditReservationId = $reservation['transaction_id'] ?? null;

        $job = $this->keywordService->createKeywordResearch($dto, $creditReservationId);

        // If duplicate was returned (job not just created), reverse reservation so user isn't charged
        if ($creditReservationId && ! $job->wasRecentlyCreated) {
            app(WalletServiceInterface::class)->reverseReservation($creditReservationId);
        }

        return $this->responseModifier
            ->setData([
                'id' => $job->id,
                'query' => $job->query,
                'status' => $job->status,
                'created_at' => $job->created_at,
            ])
            ->setMessage('Keyword research job created successfully')
            ->setResponseCode(201)
            ->response();
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

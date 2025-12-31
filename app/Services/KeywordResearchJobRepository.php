<?php

namespace App\Services;

use App\Models\KeywordResearchJob;

class KeywordResearchJobRepository
{
    /**
     * Create keyword research job with optional fields
     */
    public function createWithOptionalFields(array $baseData, array $optionalData): KeywordResearchJob
    {
        $data = array_merge($baseData, array_filter($optionalData, fn($value) => $value !== null));
        
        return KeywordResearchJob::create($data);
    }

    /**
     * Check if duplicate job exists (same user, same query)
     * First checks for in-progress jobs, then checks for completed jobs with results
     */
    public function findRecentDuplicate(int $userId, string $query, int $minutes = 5): ?KeywordResearchJob
    {
        // First, check for in-progress jobs (pending or processing) within the time window
        $inProgress = KeywordResearchJob::where('user_id', $userId)
            ->where('query', $query)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->whereIn('status', [KeywordResearchJob::STATUS_PENDING, KeywordResearchJob::STATUS_PROCESSING])
            ->orderBy('created_at', 'desc')
            ->first();
        
        if ($inProgress) {
            return $inProgress;
        }
        
        // If no in-progress job, check for completed job with results (reuse existing results)
        // Check if job has keywords associated with it (more reliable than just checking result field)
        $completed = KeywordResearchJob::where('user_id', $userId)
            ->where('query', $query)
            ->where('status', KeywordResearchJob::STATUS_COMPLETED)
            ->whereHas('keywords', function ($query) {
                $query->whereNotNull('keyword')->where('keyword', '!=', '');
            })
            ->first();
        
        if ($completed) {
            return $completed;
        }
        
        return null;
    }
}


<?php

namespace App\Services\Keyword;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KeywordIntentService
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.keyword_intent.url', 'http://localhost:8002'), '/');
        $this->timeout = (int) config('services.keyword_intent.timeout', 60);
    }

    /**
     * Rank keywords by informational intent. Sends up to 1000 keywords to the microservice and returns top 100.
     *
     * @param  array<int, string>  $keywords  List of keyword strings (max 1000)
     * @return array<int, array{keyword: string, informational_score: float}>  Top 100 with scores
     */
    public function rankByInformationalIntent(array $keywords): array
    {
        if (empty($keywords)) {
            return [];
        }

        $keywords = array_values(array_map('trim', array_filter($keywords, fn ($k) => is_string($k) && $k !== '')));
        $keywords = array_slice($keywords, 0, 1000);

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->post($this->baseUrl . '/rank', [
                    'keywords' => $keywords,
                ]);

            if (!$response->successful()) {
                Log::warning('Keyword intent service returned non-2xx', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $data = $response->json();
            $topKeywords = $data['top_keywords'] ?? [];

            return is_array($topKeywords) ? $topKeywords : [];
        } catch (ConnectionException $e) {
            Log::error('Keyword intent service connection failed', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);
            return [];
        } catch (\Throwable $e) {
            Log::error('Keyword intent service error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Check if the keyword intent microservice is reachable.
     */
    public function health(): bool
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/health');
            return $response->successful() && ($response->json('spacy_loaded') === true);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

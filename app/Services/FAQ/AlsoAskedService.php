<?php

namespace App\Services\FAQ;

use App\Interfaces\KeywordCacheRepositoryInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AlsoAskedService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $timeout;
    protected int $cacheTTL;
    protected KeywordCacheRepositoryInterface $cacheRepository;

    public function __construct(KeywordCacheRepositoryInterface $cacheRepository)
    {
        $this->baseUrl = config('services.alsoasked.base_url', 'https://alsoaskedapi.com/v1');
        $this->apiKey = config('services.alsoasked.api_key');
        $this->timeout = config('services.alsoasked.timeout', 30);
        $this->cacheTTL = config('services.alsoasked.cache_ttl', 86400);
        $this->cacheRepository = $cacheRepository;
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && !empty($this->baseUrl);
    }

    public function search($terms, string $language = 'en', string $region = 'us', int $depth = 2, bool $fresh = false): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $termsArray = is_array($terms) ? $terms : [$terms];

        if (empty($termsArray)) {
            return [];
        }

        $searchTerm = $termsArray[0];
        $cacheKey = $this->getCacheKey($searchTerm, $language, $region, $depth);

        if (!$fresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $questions = $this->fetchQuestions($termsArray, $language, $region, $depth);

            if (!empty($questions)) {
                Cache::put($cacheKey, $questions, now()->addSeconds($this->cacheTTL));
            }

            $keywords = $this->extractKeywordsFromQuestions($questions);
            if (!empty($keywords)) {
                $this->cacheKeywordsInDatabase($keywords, $language, $region);
            }

            return $questions;
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function fetchQuestions(array $terms, string $language, string $region, int $depth): array
    {
        try {
            $result = $this->createSearchJob($terms, $language, $region, $depth);
            
            if (is_array($result)) {
                $status = $result['status'] ?? 'unknown';
                
                if ($status === 'success') {
                    return $this->extractQuestions($result);
                }
                
                if (in_array($status, ['no_records', 'failed', 'error'])) {
                    return [];
                }
                
                if (isset($result['queries']) && !empty($result['queries'])) {
                    return $this->extractQuestions($result);
                }
                
                return [];
            }
            
            if (empty($result)) {
                return [];
            }

            if (is_string($result)) {
                return [];
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function createAsyncSearchJob(array $terms, string $language = 'en', string $region = 'us', int $depth = 2): ?string
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $endpoint = $this->baseUrl . '/search';

        $payload = [
            'terms' => $terms,
            'language' => $language,
            'region' => $region,
            'depth' => $depth,
            'async' => true,  // Use true async mode to get job ID immediately
            'fresh' => false,
            'notify_webhooks' => false,
        ];

        try {
            // Reduced timeout since async mode should return immediately
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $this->apiKey,
                    'User-Agent' => 'Luminiaeo-Backend/1.0.0',
                ])
                ->post($endpoint, $payload);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            
            // In async mode, API should return job ID immediately
            if (isset($data['id'])) {
                return $data['id'];
            }

            // Sometimes the API might return the ID in a different field
            if (isset($data['search_id'])) {
                return $data['search_id'];
            }

            // If status is 'running' and we have an ID, use it
            if (isset($data['status']) && $data['status'] === 'running' && isset($data['id'])) {
                return $data['id'];
            }

            return null;

        } catch (\Exception $e) {
            return null;
        }
    }

    public function getSearchResults(string $searchId): ?array
    {
        if (empty($searchId) || !is_string($searchId)) {
            return null;
        }

        $endpoint = $this->baseUrl . '/search/' . $searchId;

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Api-Key' => $this->apiKey,
                    'User-Agent' => 'Luminiaeo-Backend/1.0.0',
                ])
                ->get($endpoint);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            
            // Log AlsoAsked API response
            Log::info('AlsoAsked API Response', [
                'search_id' => $searchId,
                'response' => $data,
            ]);

            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function createSearchJob(array $terms, string $language, string $region, int $depth)
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $endpoint = $this->baseUrl . '/search';

        $payload = [
            'terms' => $terms,
            'language' => $language,
            'region' => $region,
            'depth' => $depth,
            'async' => false,
            'fresh' => false,
            'notify_webhooks' => false,
        ];

        try {
            $syncTimeout = max($this->timeout, 60);
            
            $response = Http::timeout($syncTimeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $this->apiKey,
                    'User-Agent' => 'Luminiaeo-Backend/1.0.0',
                ])
                ->post($endpoint, $payload);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            
            if (isset($data['status']) && $data['status'] === 'success' && isset($data['queries'])) {
                return $data;
            }
            
            if (isset($data['id'])) {
                $status = $data['status'] ?? 'unknown';
                
                if ($status === 'success' && isset($data['queries'])) {
                    return $data;
                }
                
                if ($status === 'running' || empty($data['queries'])) {
                    $finalResult = $this->waitForJobCompletion($data['id'], $syncTimeout);
                    return $finalResult;
                }
                
                if (in_array($status, ['success', 'no_records', 'failed', 'error'])) {
                    return $data;
                }
                
                $finalResult = $this->waitForJobCompletion($data['id'], $syncTimeout);
                return $finalResult;
            }

            // If we get here, the response structure is unexpected
            return null;

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function waitForJobCompletion(string $searchId, int $maxWaitTime): ?array
    {
        $endpoint = $this->baseUrl . '/search/' . $searchId;
        $pollInterval = 2;
        $startTime = time();

        while ((time() - $startTime) < $maxWaitTime) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'X-Api-Key' => $this->apiKey,
                        'User-Agent' => 'Luminiaeo-Backend/1.0.0',
                    ])
                    ->get($endpoint);

                if ($response->failed()) {
                    if ($response->status() >= 400 && $response->status() < 500) {
                        return null;
                    }
                    
                    sleep($pollInterval);
                    continue;
                }

                $data = $response->json();
                $status = $data['status'] ?? 'unknown';

                if ($status === 'success') {
                    return $data;
                }

                if (in_array($status, ['no_records', 'failed', 'error'])) {
                    return $data;
                }

                if ($status === 'running') {
                    sleep($pollInterval);
                    continue;
                }

                sleep($pollInterval);

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                sleep($pollInterval);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    protected function pollSearchResults(string $searchId): array
    {
        $endpoint = $this->baseUrl . '/search/' . $searchId;
        $maxPollAttempts = 60;
        $pollInterval = 3;
        $startTime = time();
        $maxWaitTime = $this->timeout;

        for ($attempt = 0; $attempt < $maxPollAttempts; $attempt++) {
            if ((time() - $startTime) > $maxWaitTime) {
                throw new \RuntimeException('AlsoAsked API polling timeout after ' . (time() - $startTime) . ' seconds');
            }

            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'X-Api-Key' => $this->apiKey,
                        'User-Agent' => 'Luminiaeo-Backend/1.0.0',
                    ])
                    ->get($endpoint);

            if ($response->failed()) {
                if ($response->status() >= 400 && $response->status() < 500) {
                    $errorMsg = 'AlsoAsked API error (HTTP ' . $response->status() . '): ' . $response->body();
                    throw new \RuntimeException($errorMsg);
                }
                sleep($pollInterval);
                continue;
            }

                $data = $response->json();
                $status = $data['status'] ?? 'unknown';

                if ($status === 'success') {
                    return $this->extractQuestions($data);
                }

                if (in_array($status, ['failed', 'no_records'])) {
                    return [];
                }

                if ($status === 'running') {
                    sleep($pollInterval);
                    continue;
                }

                sleep($pollInterval);

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($attempt >= $maxPollAttempts - 1) {
                    throw new \RuntimeException('AlsoAsked API connection errors after ' . ($attempt + 1) . ' attempts: ' . $e->getMessage());
                }
                sleep($pollInterval);
            } catch (\Exception $e) {
                throw $e;
            }
        }

        throw new \RuntimeException('AlsoAsked API polling timeout after ' . $maxPollAttempts . ' attempts');
    }

    public function extractQuestions(array $data): array
    {
        $questions = [];

        // Handle queries array structure: data.queries[].results[].question
        if (isset($data['queries']) && is_array($data['queries'])) {
            foreach ($data['queries'] as $query) {
                // Check if query itself has a question field
                if (isset($query['question']) && is_string($query['question'])) {
                    $questions[] = trim($query['question']);
                }
                
                // Check query.results array
                if (isset($query['results']) && is_array($query['results'])) {
                    foreach ($query['results'] as $result) {
                        if (isset($result['question']) && is_string($result['question'])) {
                            $questions[] = trim($result['question']);
                        }
                        // Handle nested results (sub-questions)
                        if (isset($result['results']) && is_array($result['results'])) {
                            foreach ($result['results'] as $subResult) {
                                if (isset($subResult['question']) && is_string($subResult['question'])) {
                                    $questions[] = trim($subResult['question']);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Handle direct questions array: data.questions[]
        if (isset($data['questions']) && is_array($data['questions'])) {
            foreach ($data['questions'] as $question) {
                if (is_string($question)) {
                    $questions[] = trim($question);
                } elseif (is_array($question)) {
                    if (isset($question['question']) && is_string($question['question'])) {
                        $questions[] = trim($question['question']);
                    } elseif (isset($question['text']) && is_string($question['text'])) {
                        $questions[] = trim($question['text']);
                    }
                }
            }
        }

        // Handle results array: data.results[].questions[]
        if (isset($data['results']) && is_array($data['results'])) {
            foreach ($data['results'] as $result) {
                if (isset($result['questions']) && is_array($result['questions'])) {
                    foreach ($result['questions'] as $question) {
                        if (is_string($question)) {
                            $questions[] = trim($question);
                        } elseif (is_array($question)) {
                            if (isset($question['question']) && is_string($question['question'])) {
                                $questions[] = trim($question['question']);
                            } elseif (isset($question['text']) && is_string($question['text'])) {
                                $questions[] = trim($question['text']);
                            }
                        }
                    }
                }
                // Also check if result itself has a question
                if (isset($result['question']) && is_string($result['question'])) {
                    $questions[] = trim($result['question']);
                }
            }
        }

        // Handle flat array of strings
        if (empty($questions) && isset($data[0]) && is_string($data[0])) {
            $questions = array_map('trim', $data);
        }

        // Filter and deduplicate
        $questions = array_filter(array_unique($questions), function ($question) {
            return !empty($question) && strlen($question) > 5;
        });

        return array_values($questions);
    }

    public function extractKeywordsFromQuestions(array $questions): array
    {
        $keywords = [];
        $questionWords = ['what', 'how', 'why', 'when', 'where', 'who', 'can', 'should', 'is', 'are', 'do', 'does', 'will', 'would', 'could', 'may', 'might'];

        foreach ($questions as $question) {
            $text = strtolower(trim(str_replace('?', '', $question)));

            $words = explode(' ', $text);
            while (!empty($words) && in_array($words[0], $questionWords)) {
                array_shift($words);
            }

            $keyword = implode(' ', $words);

            $keyword = preg_replace('/\s+/', ' ', trim($keyword));

            if (!empty($keyword) && strlen($keyword) > 2) {
                $keywords[] = $keyword;
            }

            foreach ($words as $word) {
                $word = trim($word);
                if (strlen($word) >= 3 && !in_array($word, $questionWords)) {
                    $keywords[] = $word;
                }
            }
        }

        return array_values(array_unique($keywords));
    }

    protected function cacheKeywordsInDatabase(array $keywords, string $languageCode, string $region): void
    {
        if (empty($keywords)) {
            return;
        }

        $locationCode = $this->mapRegionToLocationCode($region);

        $cacheData = [];

        foreach ($keywords as $keyword) {
            if (strlen($keyword) < 3) {
                continue;
            }

            $cacheData[] = [
                'keyword' => $keyword,
                'language_code' => $languageCode,
                'location_code' => $locationCode,
                'search_volume' => null,
                'competition' => null,
                'cpc' => null,
                'source' => 'alsoasked',
                'metadata' => [
                    'region' => $region,
                    'cached_at' => now()->toIso8601String(),
                ],
            ];
        }

        if (!empty($cacheData)) {
            try {
                $this->cacheRepository->bulkUpdate($cacheData);
            } catch (\Exception $e) {
                // Silently fail cache update
            }
        }
    }

    protected function mapRegionToLocationCode(string $region): int
    {
        $locationCodeService = app(LocationCodeService::class);
        return $locationCodeService->mapRegionToLocationCode($region, 2840);
    }

    public function getKeywords($terms, string $language = 'en', string $region = 'us', int $depth = 2, bool $fresh = false): array
    {
        $questions = $this->search($terms, $language, $region, $depth, $fresh);

        if (empty($questions)) {
            return [];
        }

        $keywords = $this->extractKeywordsFromQuestions($questions);

        $allKeywords = array_merge($keywords, $questions);

        return array_values(array_unique($allKeywords));
    }

    protected function getCacheKey(string $term, string $language, string $region, int $depth): string
    {
        return 'alsoasked:' . md5(strtolower($term) . '|' . $language . '|' . $region . '|' . $depth);
    }
}

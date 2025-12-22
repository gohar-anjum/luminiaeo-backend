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
            Log::info('AlsoAsked service not available, skipping');
            return [];
        }

        $termsArray = is_array($terms) ? $terms : [$terms];

        if (empty($termsArray)) {
            return [];
        }

        $searchTerm = $termsArray[0];
        $cacheKey = $this->getCacheKey($searchTerm, $language, $region, $depth);

        if (!$fresh && Cache::has($cacheKey)) {
            Log::info('AlsoAsked results retrieved from cache', [
                'term' => $searchTerm,
            ]);
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
            Log::error('Error fetching questions from AlsoAsked', [
                'error' => $e->getMessage(),
                'terms' => $termsArray,
            ]);
            return [];
        }
    }

    protected function fetchQuestions(array $terms, string $language, string $region, int $depth): array
    {
        $endpoint = $this->baseUrl . '/search';

        $payload = [
            'terms' => $terms,
            'language' => $language,
            'region' => $region,
            'depth' => $depth,
        ];

        Log::info('Calling AlsoAsked API', [
            'endpoint' => $endpoint,
            'terms' => $terms,
            'language' => $language,
            'region' => $region,
        ]);

        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'X-Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'Luminiaeo-Backend/1.0.0',
            ])
            ->post($endpoint, $payload);

        if ($response->failed()) {
            Log::error('AlsoAsked API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('AlsoAsked API error: ' . $response->body());
        }

        $data = $response->json();

        return $this->extractQuestions($data);
    }

    protected function extractQuestions(array $data): array
    {
        $questions = [];

        if (isset($data['questions']) && is_array($data['questions'])) {
            foreach ($data['questions'] as $question) {
                if (is_string($question)) {
                    $questions[] = trim($question);
                } elseif (is_array($question) && isset($question['question'])) {
                    $questions[] = trim($question['question']);
                } elseif (is_array($question) && isset($question['text'])) {
                    $questions[] = trim($question['text']);
                }
            }
        }

        if (isset($data['results']) && is_array($data['results'])) {
            foreach ($data['results'] as $result) {
                if (isset($result['questions']) && is_array($result['questions'])) {
                    foreach ($result['questions'] as $question) {
                        if (is_string($question)) {
                            $questions[] = trim($question);
                        } elseif (is_array($question) && isset($question['question'])) {
                            $questions[] = trim($question['question']);
                        }
                    }
                }
            }
        }

        if (empty($questions) && isset($data[0]) && is_string($data[0])) {
            $questions = array_map('trim', $data);
        }

        $questions = array_filter(array_unique($questions), function ($question) {
            return !empty($question) && strlen($question) > 5;
        });

        Log::info('AlsoAsked questions extracted', [
            'questions_count' => count($questions),
            'questions' => array_slice($questions, 0, 10),
        ]);

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
                Log::info('Cached AlsoAsked keywords in database', [
                    'count' => count($cacheData),
                    'source' => 'alsoasked',
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to cache AlsoAsked keywords in database', [
                    'error' => $e->getMessage(),
                    'count' => count($cacheData),
                ]);
            }
        }
    }

    protected function mapRegionToLocationCode(string $region): int
    {
        $mapping = [
            'us' => 2840,
            'uk' => 2826,
            'au' => 2036,
            'ca' => 2124,
            'nz' => 2752,
        ];

        return $mapping[strtolower($region)] ?? 2840;
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

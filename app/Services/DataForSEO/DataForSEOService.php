<?php

namespace App\Services\DataForSEO;

use App\DTOs\SearchVolumeDTO;
use App\Exceptions\DataForSEOException;
use App\Interfaces\KeywordCacheRepositoryInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DataForSEOService
{
    protected string $baseUrl;
    protected string $login;
    protected string $password;
    protected int $cacheTTL;
    protected KeywordCacheRepositoryInterface $cacheRepository;

    public function __construct(KeywordCacheRepositoryInterface $cacheRepository)
    {
        // Initialize with empty strings to avoid type errors during autoload
        // Validation will happen when service is actually used
        $this->baseUrl = '';
        $this->login = '';
        $this->password = '';
        $this->cacheTTL = config('services.dataforseo.cache_ttl', 86400);
        $this->cacheRepository = $cacheRepository;
    }

    protected function ensureConfigured(): void
    {
        if (empty($this->baseUrl) || empty($this->login) || empty($this->password)) {
            $baseUrl = config('services.dataforseo.base_url');
            $login = config('services.dataforseo.login');
            $password = config('services.dataforseo.password');

            if (empty($baseUrl) || empty($login) || empty($password)) {
                throw new DataForSEOException(
                    'DataForSEO configuration is incomplete. Please check your environment variables.',
                    500,
                    'CONFIG_ERROR'
                );
            }

            $this->baseUrl = (string) $baseUrl;
            $this->login = (string) $login;
            $this->password = (string) $password;
        }
    }

    /**
     * Check if DataForSEO API should be called (circuit breaker)
     */
    protected function shouldCallAPI(): bool
    {
        $circuitBreakerKey = 'dataforseo:circuit_breaker';
        $failureCount = Cache::get($circuitBreakerKey . ':failures', 0);
        $lastFailure = Cache::get($circuitBreakerKey . ':last_failure');

        if ($failureCount >= 5) {
            if ($lastFailure && now()->diffInMinutes($lastFailure) < 10) {
                Log::warning('DataForSEO circuit breaker is open, skipping API call', [
                    'failure_count' => $failureCount,
                    'last_failure' => $lastFailure,
                ]);
                return false;
            }
            Cache::forget($circuitBreakerKey . ':failures');
        }

        return true;
    }

    /**
     * Record API failure for circuit breaker
     */
    protected function recordAPIFailure(): void
    {
        $circuitBreakerKey = 'dataforseo:circuit_breaker';
        $failureCount = Cache::increment($circuitBreakerKey . ':failures');
        Cache::put($circuitBreakerKey . ':last_failure', now(), now()->addHours(1));
        
        Log::warning('DataForSEO API failure recorded', [
            'failure_count' => $failureCount,
        ]);
    }

    /**
     * Record API success for circuit breaker
     */
    protected function recordAPISuccess(): void
    {
        $circuitBreakerKey = 'dataforseo:circuit_breaker';
        Cache::forget($circuitBreakerKey . ':failures');
        Cache::forget($circuitBreakerKey . ':last_failure');
    }

    protected function client()
    {
        $this->ensureConfigured();
        return Http::withBasicAuth($this->login, $this->password)
            ->acceptJson()
            ->baseUrl($this->baseUrl)
            ->timeout(config('services.dataforseo.timeout', 30))
            ->retry(3, 100);
    }
    public function getSearchVolume(
        array $keywords,
        string $languageCode = 'en',
        int $locationCode = 2840
    ): array {
        if (empty($keywords)) {
            throw new InvalidArgumentException('Keywords array cannot be empty');
        }

        $maxKeywords = config('services.dataforseo.search_volume.max_keywords', 100);
        if (count($keywords) > $maxKeywords) {
            throw new InvalidArgumentException("Maximum {$maxKeywords} keywords allowed per request");
        }

        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || empty(trim($keyword))) {
                throw new InvalidArgumentException('Invalid keyword: ' . $keyword);
            }
            if (strlen($keyword) > 255) {
                throw new InvalidArgumentException('Keyword exceeds maximum length: ' . $keyword);
            }
        }

        $lockKey = 'dataforseo:lock:search_volume:' . md5(serialize([$keywords, $languageCode, $locationCode]));
        $timeout = config('cache_locks.search_volume.timeout', 30);
        
        return Cache::lock($lockKey, $timeout)->get(function () use ($keywords, $languageCode, $locationCode) {
            $cachedResults = [];
            $uncachedKeywords = [];
            
            foreach ($keywords as $keyword) {
                $cache = $this->cacheRepository->findValid($keyword, $languageCode, $locationCode);
                if ($cache) {
                    try {
                        $cachedResults[] = SearchVolumeDTO::fromArray([
                            'keyword' => $cache->keyword,
                            'search_volume' => $cache->search_volume,
                            'competition' => $cache->competition,
                            'cpc' => $cache->cpc,
                            'competition_index' => $cache->metadata['competition_index'] ?? null,
                            'keyword_info' => [
                                'monthly_searches' => $cache->metadata['monthly_searches'] ?? null,
                                'low_top_of_page_bid' => $cache->metadata['low_top_of_page_bid'] ?? null,
                                'high_top_of_page_bid' => $cache->metadata['high_top_of_page_bid'] ?? null,
                            ],
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('Failed to convert cached keyword to DTO', [
                            'keyword' => $keyword,
                            'error' => $e->getMessage(),
                        ]);
                        $uncachedKeywords[] = $keyword;
                    }
                } else {
                    $uncachedKeywords[] = $keyword;
                }
            }
            
            if (empty($uncachedKeywords)) {
                Log::info('All keywords found in database cache', [
                    'keywords_count' => count($keywords),
                ]);
                return $cachedResults;
            }
            
            $cacheKey = $this->getCacheKey('search_volume', [
                'keywords' => $uncachedKeywords,
                'language_code' => $languageCode,
                'location_code' => $locationCode,
            ]);

            if (Cache::has($cacheKey)) {
                Log::info('Cache hit for search volume (Laravel cache)', [
                    'keywords_count' => count($uncachedKeywords),
                    'cache_key' => $cacheKey,
                ]);
                $cachedFromMemory = Cache::get($cacheKey);
                return array_merge($cachedResults, $cachedFromMemory);
            }
            
            return $this->fetchSearchVolumeFromAPI($uncachedKeywords, $languageCode, $locationCode, $cachedResults);
        });
    }
    
    /**
     * Fetch search volume from API and cache results
     */
    private function fetchSearchVolumeFromAPI(
        array $keywords,
        string $languageCode,
        int $locationCode,
        array $existingResults = []
    ): array {

        $payload = [
            'data' => [
                [
                    'keywords' => $keywords,
                    'language_code' => $languageCode,
                    'location_code' => $locationCode,
                ]
            ]
        ];

        try {
            Log::info('Fetching search volume from DataForSEO API', [
                'keywords_count' => count($keywords),
                'language_code' => $languageCode,
                'location_code' => $locationCode,
            ]);

            Log::info('DataForSEO Search Volume API Request Payload', [
                'endpoint' => '/keywords_data/google_ads/search_volume/live',
                'payload' => $payload,
            ]);

            $httpResponse = $this->client()
                ->post('/keywords_data/google_ads/search_volume/live', $payload)
                ->throw();

            // Log the raw response for debugging
            Log::info('DataForSEO Search Volume API Response', [
                'keywords_count' => count($keywords),
                'status_code' => $httpResponse->status(),
                'response_keys' => array_keys($httpResponse->json() ?? []),
                'full_response' => $httpResponse->json(),
            ]);

            $response = $httpResponse->json();

            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                Log::error('Invalid API response structure: missing tasks', ['response' => $response]);
                throw new DataForSEOException(
                    'Invalid API response: missing tasks',
                    500,
                    'INVALID_RESPONSE'
                );
            }

            $task = $response['tasks'][0];

            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                $errorMessage = $task['status_message'] ?? 'Unknown error';
                Log::error('DataForSEO API error', [
                    'status_code' => $task['status_code'],
                    'status_message' => $errorMessage,
                ]);
                $this->recordAPIFailure();
                throw new DataForSEOException(
                    'DataForSEO API error: ' . $errorMessage,
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            $this->recordAPISuccess();

            if (!isset($task['result']) || !is_array($task['result']) || empty($task['result'])) {
                Log::warning('No results found in API response', ['task' => $task]);
                return $existingResults;
            }

            $items = $task['result'];
            $results = [];
            $cacheData = [];
            
            foreach ($items as $item) {
                try {
                    $dto = SearchVolumeDTO::fromArray($item);
                    $results[] = $dto;
                    
                    $cacheData[] = [
                        'keyword' => $dto->keyword,
                        'language_code' => $languageCode,
                        'location_code' => $locationCode,
                        'search_volume' => $dto->searchVolume,
                        'competition' => $dto->competition,
                        'cpc' => $dto->cpc,
                        'source' => 'dataforseo_search_volume',
                        'metadata' => [
                            'competition_index' => $dto->competitionIndex,
                            'monthly_searches' => $dto->keywordInfo['monthly_searches'] ?? null,
                            'low_top_of_page_bid' => $dto->keywordInfo['low_top_of_page_bid'] ?? null,
                            'high_top_of_page_bid' => $dto->keywordInfo['high_top_of_page_bid'] ?? null,
                        ],
                    ];
                } catch (\Exception $e) {
                    Log::warning('Failed to process keyword result', [
                        'keyword' => $item['keyword'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            if (!empty($cacheData)) {
                try {
                    $this->cacheRepository->bulkUpdate($cacheData);
                } catch (\Exception $e) {
                    Log::warning('Failed to store search volume in database cache', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            $cacheKey = $this->getCacheKey('search_volume', [
                'keywords' => $keywords,
                'language_code' => $languageCode,
                'location_code' => $locationCode,
            ]);
            Cache::put($cacheKey, $results, now()->addSeconds($this->cacheTTL));

            $allResults = array_merge($existingResults, $results);

            Log::info('Successfully fetched search volume', [
                'keywords_count' => count($keywords),
                'results_count' => count($results),
                'total_results' => count($allResults),
            ]);

            if (empty($allResults) && !empty($keywords)) {
                Log::warning('No valid results returned from API', [
                    'keywords_count' => count($keywords),
                ]);
                return [];
            }

            return $allResults;
        } catch (RequestException $e) {
            Log::error('DataForSEO API request failed', [
                'error' => $e->getMessage(),
                'keywords_count' => count($keywords),
                'response' => $e->response?->json(),
            ]);

            if (!empty($existingResults)) {
                Log::info('Returning cached results due to API failure', [
                    'cached_count' => count($existingResults),
                ]);
                return $existingResults;
            }

            throw new DataForSEOException(
                'Failed to fetch search volume data: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        } catch (DataForSEOException $e) {
            if (!empty($existingResults)) {
                Log::info('Returning cached results due to DataForSEO error', [
                    'cached_count' => count($existingResults),
                    'error' => $e->getMessage(),
                ]);
                return $existingResults;
            }
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in fetchSearchVolumeFromAPI', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (!empty($existingResults)) {
                Log::info('Returning cached results due to unexpected error', [
                    'cached_count' => count($existingResults),
                ]);
                return $existingResults;
            }

            throw new DataForSEOException(
                'An unexpected error occurred: ' . $e->getMessage(),
                500,
                'UNEXPECTED_ERROR',
                $e
            );
        }
    }

    public function getKeywordsForSite(
        string $target,
        int $locationCode = 2840,
        string $languageCode = 'en',
        bool $searchPartners = true,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $includeSerpInfo = false,
        ?string $tag = null,
        ?int $limit = null
    ): array
    {
        $defaultLimit = config('services.dataforseo.keywords_for_site.default_limit', 100);
        $limit = $limit ?? $defaultLimit;

        $target = preg_replace('/^https?:\/\//i', '', trim($target));
        $target = rtrim($target, '/');

        if (empty($target)) {
            throw new InvalidArgumentException('Target website/domain cannot be empty');
        }

        $cacheKey = $this->getCacheKey('keywords_for_site', [
            'target' => $target,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
            'search_partners' => $searchPartners,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'include_serp_info' => $includeSerpInfo,
        ]);

        if (Cache::has($cacheKey)) {
            Log::info('Cache hit for keywords for site (Laravel cache)', [
                'target' => $target,
                'cache_key' => $cacheKey,
            ]);
            $results = Cache::get($cacheKey);
            $maxLimit = config('services.dataforseo.keywords_for_site.max_limit', 1000);
            $limit = min($limit, $maxLimit);
            return array_slice($results, 0, $limit);
        }

        $maxLimit = config('services.dataforseo.keywords_for_site.max_limit', 1000);
        $limit = min($limit, $maxLimit);

        $taskData = [
            'target' => $target,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
            'search_partners' => $searchPartners,
        ];

        if ($tag !== null) {
            $taskData['tag'] = $tag;
        }
        
        if ($limit > 0) {
            $taskData['limit'] = $limit;
        }

        $payload = [
            'data' => [
                $taskData
            ]
        ];

        if (!$this->shouldCallAPI()) {
            Log::warning('DataForSEO circuit breaker is open, cannot fetch keywords for site', [
                'target' => $target,
            ]);
            throw new DataForSEOException(
                'DataForSEO service is temporarily unavailable (circuit breaker open). Please try again later.',
                503,
                'SERVICE_UNAVAILABLE'
            );
        }

        try {
            Log::info('Fetching keywords for site from DataForSEO API', [
                'target' => $target,
                'location_code' => $locationCode,
                'language_code' => $languageCode,
            ]);

            Log::info('DataForSEO Keywords For Site API Request Payload', [
                'endpoint' => '/keywords_data/google_ads/keywords_for_site/live',
                'payload' => $payload,
            ]);

            $httpResponse = $this->client()
                ->post('/keywords_data/google_ads/keywords_for_site/live', $payload)
                ->throw();
            
            $this->recordAPISuccess();

            Log::info('DataForSEO Keywords For Site API Response', [
                'target' => $target,
                'status_code' => $httpResponse->status(),
                'response_keys' => array_keys($httpResponse->json() ?? []),
                'full_response' => $httpResponse->json(),
            ]);

            $response = $httpResponse->json();

            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                Log::error('Invalid API response structure: missing tasks', ['response' => $response]);
                throw new DataForSEOException(
                    'Invalid API response: missing tasks',
                    500,
                    'INVALID_RESPONSE'
                );
            }

            $task = $response['tasks'][0];

            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                $errorMessage = $task['status_message'] ?? 'Unknown error';
                Log::error('DataForSEO API error', [
                    'status_code' => $task['status_code'],
                    'status_message' => $errorMessage,
                ]);
                $this->recordAPIFailure();
                throw new DataForSEOException(
                    'DataForSEO API error: ' . $errorMessage,
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            // Record success for circuit breaker
            $this->recordAPISuccess();

            if (!isset($task['result']) || !is_array($task['result']) || empty($task['result'])) {
                Log::warning('No results found in API response', ['task' => $task]);
                return [];
            }

            $items = $task['result'];

            $results = array_map(function ($item) {
                return \App\DTOs\KeywordsForSiteDTO::fromArray($item);
            }, $items);

            Cache::put($cacheKey, $results, now()->addSeconds($this->cacheTTL));

            $this->cacheKeywordsInDatabase($results, $languageCode, $locationCode);

            Log::info('Successfully fetched keywords for site', [
                'target' => $target,
                'results_count' => count($results),
                'limit' => $limit,
            ]);

            return array_slice($results, 0, $limit);
        } catch (RequestException $e) {
            Log::error('DataForSEO API request failed', [
                'error' => $e->getMessage(),
                'target' => $target,
                'response' => $e->response?->json(),
            ]);
            $this->recordAPIFailure();
            throw new DataForSEOException(
                'Failed to fetch keywords for site: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in getKeywordsForSite', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new DataForSEOException(
                'An unexpected error occurred: ' . $e->getMessage(),
                500,
                'UNEXPECTED_ERROR',
                $e
            );
        }
    }

    protected function getCacheKey(string $type, array $params): string
    {
        $key = sprintf(
            'dataforseo:%s:%s',
            $type,
            md5(serialize($params))
        );

        return $key;
    }

    protected function cacheKeywordsInDatabase(array $keywords, string $languageCode, int $locationCode): void
    {
        if (empty($keywords)) {
            return;
        }

        $cacheData = [];

        foreach ($keywords as $dto) {
            if (!($dto instanceof \App\DTOs\KeywordsForSiteDTO)) {
                continue;
            }

            $cacheData[] = [
                'keyword' => $dto->keyword,
                'language_code' => $languageCode,
                'location_code' => $locationCode,
                'search_volume' => $dto->searchVolume,
                'competition' => $dto->competition,
                'cpc' => $dto->cpc,
                'source' => 'dataforseo_keywords_for_site',
                'metadata' => [
                    'target' => $dto->target ?? null,
                    'monthly_searches' => $dto->monthlySearches,
                    'competition_index' => $dto->competitionIndex,
                    'cached_at' => now()->toIso8601String(),
                ],
            ];
        }

        if (!empty($cacheData)) {
            try {
                $this->cacheRepository->bulkUpdate($cacheData);
                Log::info('Cached DataForSEO keywords in database', [
                    'count' => count($cacheData),
                    'source' => 'dataforseo_keywords_for_site',
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to cache keywords in database', [
                    'error' => $e->getMessage(),
                    'count' => count($cacheData),
                ]);
            }
        }
    }

    public function getKeywordIdeas(
        string $seedKeyword,
        string $languageCode = 'en',
        int $locationCode = 2840,
        ?int $maxResults = null
    ): array {
        $defaultLimit = config('services.dataforseo.keyword_ideas.default_limit', 100);
        $maxLimit = config('services.dataforseo.keyword_ideas.max_limit', 1000);
        
        $maxResults = $maxResults ?? $defaultLimit;
        $maxResults = min($maxResults, $maxLimit);
        
        $payload = [
            [
                'keywords' => [$seedKeyword],
                'language_code' => $languageCode,
                'location_code' => (int) $locationCode,
            ]
        ];

        try {
            Log::info('DataForSEO Keywords For Keywords API Request Payload', [
                'endpoint' => '/keywords_data/google_ads/keywords_for_keywords/live',
                'payload' => $payload,
            ]);

            $httpResponse = $this->client()
                ->post('/keywords_data/google_ads/keywords_for_keywords/live', $payload)
                ->throw();

            Log::info('DataForSEO Keywords For Keywords API Response', [
                'seed_keyword' => $seedKeyword,
                'status_code' => $httpResponse->status(),
                'response_keys' => array_keys($httpResponse->json() ?? []),
                'full_response' => $httpResponse->json(),
            ]);

            $response = $httpResponse->json();

            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                throw new DataForSEOException('Invalid API response: missing tasks', 500, 'INVALID_RESPONSE');
            }

            $task = $response['tasks'][0];

            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                $this->recordAPIFailure();
                throw new DataForSEOException(
                    'DataForSEO API error: ' . ($task['status_message'] ?? 'Unknown error'),
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            // Record success for circuit breaker
            $this->recordAPISuccess();

            if (!isset($task['result']) || !is_array($task['result']) || empty($task['result'])) {
                return [];
            }

            // Extract items from response - result is directly an array of keyword objects
            $items = $task['result'] ?? [];
            
            // Ensure items is an array
            if (!is_array($items)) {
                $items = [];
            }
            
            // If items is a single object (not array), wrap it
            if (!empty($items) && isset($items['keyword']) && !isset($items[0])) {
                $items = [$items];
            }
            
            Log::info('DataForSEO Parsed Items', [
                'items_count' => count($items),
                'first_item_keyword' => !empty($items) && is_array($items[0]) ? ($items[0]['keyword'] ?? 'NOT_SET') : 'NO_ITEMS',
            ]);
            
            $results = [];

            foreach ($items as $index => $item) {
                if ($maxResults !== null && count($results) >= $maxResults) {
                    break;
                }

                // Skip if item is not an array
                if (!is_array($item)) {
                    continue;
                }

                // Extract keyword - this is the critical field
                $keywordValue = isset($item['keyword']) ? trim((string)$item['keyword']) : '';
                
                // Skip if keyword is empty
                if (empty($keywordValue)) {
                    continue;
                }

                // Extract competition
                $competitionValue = null;
                if (isset($item['competition'])) {
                    $comp = strtoupper((string) $item['competition']);
                    $competitionValue = match ($comp) {
                        'HIGH' => 1.0,
                        'MEDIUM' => 0.5,
                        'LOW' => 0.0,
                        default => null,
                    };
                }
                
                if ($competitionValue === null && isset($item['competition_index'])) {
                    $competitionValue = (float) $item['competition_index'] / 100.0;
                }

                // Extract CPC
                $cpc = $item['cpc'] ?? null;
                if ($cpc === null && isset($item['low_top_of_page_bid']) && isset($item['high_top_of_page_bid'])) {
                    $cpc = ($item['low_top_of_page_bid'] + $item['high_top_of_page_bid']) / 2;
                }

                // Create DTO with the keyword value
                $results[] = new \App\DTOs\KeywordDataDTO(
                    keyword: $keywordValue,
                    source: 'dataforseo_keyword_planner',
                    searchVolume: $item['search_volume'] ?? null,
                    competition: $competitionValue,
                    cpc: $cpc !== null ? (float) $cpc : null,
                );
            }
            
            Log::info('DataForSEO Parsed Results', [
                'results_count' => count($results),
                'first_result_keyword' => !empty($results) ? $results[0]->keyword : 'NO_RESULTS',
            ]);

            return $results;
        } catch (RequestException $e) {
            Log::error('DataForSEO keyword planner API request failed', [
                'seed_keyword' => $seedKeyword,
                'error' => $e->getMessage(),
            ]);
            $this->recordAPIFailure();
            throw new DataForSEOException(
                'Failed to fetch keyword ideas: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in getKeywordIdeas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new DataForSEOException(
                'An unexpected error occurred: ' . $e->getMessage(),
                500,
                'UNEXPECTED_ERROR',
                $e
            );
        }
    }

    /**
     * Get location codes from DataForSEO API
     * 
     * @param string $se Search engine (default: 'google')
     * @param string $seType Search engine type (default: 'ads_search')
     * @return array Location codes data
     */
    public function getLocationCodes(string $se = 'google', string $seType = 'ads_search'): array
    {
        $cacheKey = "dataforseo:location_codes:{$se}:{$seType}";
        $cacheTTL = config('services.dataforseo.cache_ttl', 86400); // 24 hours default

            // Try to get from cache first
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                try {
                    Log::info('DataForSEO Location Codes retrieved from cache', [
                        'cache_key' => $cacheKey,
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }
                return $cached;
            }

        try {
            $endpoint = "/serp/{$se}/ads_search/locations";
            
            try {
                Log::info('DataForSEO Location Codes API Request', [
                    'endpoint' => $endpoint,
                    'full_url' => $this->baseUrl . $endpoint,
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }

            // DataForSEO locations endpoint uses GET
            $response = $this->client()
                ->get($endpoint)
                ->throw()
                ->json();

            try {
                Log::info('DataForSEO Location Codes API Response', [
                    'status_code' => $response['status_code'] ?? null,
                    'tasks_count' => $response['tasks_count'] ?? 0,
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }

            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                throw new DataForSEOException('Invalid API response: missing tasks', 500, 'INVALID_RESPONSE');
            }

            $task = $response['tasks'][0];

            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                throw new DataForSEOException(
                    'DataForSEO API error: ' . ($task['status_message'] ?? 'Unknown error'),
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            $result = $task['result'] ?? [];
            
            // Cache the result
            Cache::put($cacheKey, $result, $cacheTTL);
            try {
                Log::info('DataForSEO Location Codes cached', [
                    'cache_key' => $cacheKey,
                    'count' => count($result),
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }

            return $result;
        } catch (RequestException $e) {
            throw new DataForSEOException(
                'Failed to get location codes: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DataForSEOException(
                'An unexpected error occurred: ' . $e->getMessage(),
                500,
                'UNEXPECTED_ERROR',
                $e
            );
        }
    }
}

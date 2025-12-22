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
        $this->baseUrl = config('services.dataforseo.base_url');
        $this->login = config('services.dataforseo.login');
        $this->password = config('services.dataforseo.password');
        $this->cacheTTL = config('services.dataforseo.cache_ttl', 86400);
        $this->cacheRepository = $cacheRepository;

        if (empty($this->baseUrl) || empty($this->login) || empty($this->password)) {
            throw new DataForSEOException(
                'DataForSEO configuration is incomplete. Please check your environment variables.',
                500,
                'CONFIG_ERROR'
            );
        }
    }

    protected function client()
    {
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

        if (count($keywords) > 100) {
            throw new InvalidArgumentException('Maximum 100 keywords allowed per request');
        }

        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || empty(trim($keyword))) {
                throw new InvalidArgumentException('Invalid keyword: ' . $keyword);
            }
            if (strlen($keyword) > 255) {
                throw new InvalidArgumentException('Keyword exceeds maximum length: ' . $keyword);
            }
        }

        $cacheKey = $this->getCacheKey('search_volume', [
            'keywords' => $keywords,
            'language_code' => $languageCode,
            'location_code' => $locationCode,
        ]);

        if (Cache::has($cacheKey)) {
            Log::info('Cache hit for search volume', [
                'keywords_count' => count($keywords),
                'cache_key' => $cacheKey,
            ]);
            return Cache::get($cacheKey);
        }

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

            $response = $this->client()
                ->post('/keywords_data/google_ads/search_volume/live', $payload)
                ->throw()
                ->json();

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
                throw new DataForSEOException(
                    'DataForSEO API error: ' . $errorMessage,
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            if (!isset($task['result']) || !is_array($task['result']) || empty($task['result'])) {
                Log::warning('No results found in API response', ['task' => $task]);
                return [];
            }

            if (!isset($task['result'][0]['items']) || !is_array($task['result'][0]['items'])) {
                Log::warning('Invalid result structure: missing items', ['task' => $task]);
                return [];
            }

            $items = $task['result'][0]['items'];

            $results = array_map(function ($item) {
                return SearchVolumeDTO::fromArray($item);
            }, $items);

            Cache::put($cacheKey, $results, now()->addSeconds($this->cacheTTL));

            Log::info('Successfully fetched search volume', [
                'keywords_count' => count($keywords),
                'results_count' => count($results),
            ]);

            return $results;
        } catch (RequestException $e) {
            Log::error('DataForSEO API request failed', [
                'error' => $e->getMessage(),
                'keywords_count' => count($keywords),
                'response' => $e->response?->json(),
            ]);

            throw new DataForSEOException(
                'Failed to fetch search volume data: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in getSearchVolume', [
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
    ): array {

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

            if ($limit !== null && $limit > 0) {
                return array_slice($results, 0, $limit);
            }

            return $results;
        }

        $payload = [
            'data' => [
                array_filter([
                    'target' => $target,
                    'location_code' => $locationCode,
                    'language_code' => $languageCode,
                    'search_partners' => $searchPartners,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'include_serp_info' => $includeSerpInfo,
                    'tag' => $tag,
                ], fn($value) => $value !== null)
            ]
        ];

        try {
            Log::info('Fetching keywords for site from DataForSEO API', [
                'target' => $target,
                'location_code' => $locationCode,
                'language_code' => $languageCode,
            ]);

            $response = $this->client()
                ->post('/keywords_data/google_ads/keywords_for_site/live', $payload)
                ->throw()
                ->json();

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
                throw new DataForSEOException(
                    'DataForSEO API error: ' . $errorMessage,
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            if (!isset($task['result']) || !is_array($task['result']) || empty($task['result'])) {
                Log::warning('No results found in API response', ['task' => $task]);
                return [];
            }

            if (!isset($task['result'][0]['items']) || !is_array($task['result'][0]['items'])) {
                Log::warning('Invalid result structure: missing items', ['task' => $task]);
                return [];
            }

            $items = $task['result'][0]['items'];

            $results = array_map(function ($item) {
                return \App\DTOs\KeywordsForSiteDTO::fromArray($item);
            }, $items);

            Cache::put($cacheKey, $results, now()->addSeconds($this->cacheTTL));

            $this->cacheKeywordsInDatabase($results, $languageCode, $locationCode);

            Log::info('Successfully fetched keywords for site', [
                'target' => $target,
                'results_count' => count($results),
            ]);

            if ($limit !== null && $limit > 0) {
                return array_slice($results, 0, $limit);
            }

            return $results;
        } catch (RequestException $e) {
            Log::error('DataForSEO API request failed', [
                'error' => $e->getMessage(),
                'target' => $target,
                'response' => $e->response?->json(),
            ]);

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
}

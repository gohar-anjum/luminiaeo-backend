<?php

namespace App\Services\DataForSEO;

use App\DTOs\SearchVolumeDTO;
use App\Exceptions\DataForSEOException;
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

    public function __construct()
    {
        $this->baseUrl = config('services.dataforseo.base_url');
        $this->login = config('services.dataforseo.login');
        $this->password = config('services.dataforseo.password');
        $this->cacheTTL = config('services.dataforseo.cache_ttl', 86400);

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

    /**
     * Get search volume for keywords
     *
     * @param array $keywords Array of keywords (1-100 items)
     * @param string $languageCode Language code (default: 'en')
     * @param int $locationCode Location code (default: 2840 for United States)
     * @return array Array of SearchVolumeDTO objects
     * @throws DataForSEOException
     * @throws InvalidArgumentException
     */
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

    protected function getCacheKey(string $type, array $params): string
    {
        $key = sprintf(
            'dataforseo:%s:%s',
            $type,
            md5(serialize($params))
        );

        return $key;
    }
}

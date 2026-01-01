<?php

namespace App\Services\Serp;

use App\DTOs\SerpKeywordDataDTO;
use App\Exceptions\SerpException;
use App\Services\LocationCodeService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SerpService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $cacheTTL;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.serp.base_url');
        $this->apiKey = config('services.serp.api_key');
        $this->cacheTTL = config('services.serp.cache_ttl', 2592000);
        $this->timeout = config('services.serp.timeout', 60);

        if (empty($this->baseUrl) || empty($this->apiKey)) {
            throw new SerpException(
                'Serp API configuration is incomplete. Please check your environment variables.',
                500,
                'CONFIG_ERROR'
            );
        }
    }

    protected function client()
    {
        return Http::withHeaders([
            'Accept' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->retry(3, 100);
    }

    public function getKeywordData(
        array $keywords,
        string $languageCode = 'en',
        int $locationCode = 2840,
        array $options = []
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

        $cacheKey = $this->getCacheKey('keyword_data', [
            'keywords' => $keywords,
            'language_code' => $languageCode,
            'location_code' => $locationCode,
            'options' => $options,
        ]);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $payload = [
            'keywords' => $keywords,
            'language_code' => $languageCode,
            'location_code' => $locationCode,
            ...$options,
        ];

        try {
            $response = $this->client()
                ->post($this->baseUrl . '/keywords', $payload)
                ->throw()
                ->json();

            if (!isset($response['data']) || !is_array($response['data'])) {
                Log::error('Invalid API response structure: missing data', ['response' => $response]);
                throw new SerpException(
                    'Invalid API response: missing data',
                    500,
                    'INVALID_RESPONSE'
                );
            }

            if (isset($response['error'])) {
                $errorMessage = $response['error']['message'] ?? 'Unknown error';
                Log::error('Serp API error', [
                    'error' => $errorMessage,
                    'code' => $response['error']['code'] ?? null,
                ]);
                throw new SerpException(
                    'Serp API error: ' . $errorMessage,
                    $response['error']['code'] ?? 500,
                    'API_ERROR'
                );
            }

            $results = array_map(function ($item) {
                return SerpKeywordDataDTO::fromArray($item);
            }, $response['data']);

            Cache::put($cacheKey, $results, now()->addSeconds($this->cacheTTL));

            return $results;
        } catch (RequestException $e) {
            Log::error('Serp API request failed', [
                'error' => $e->getMessage(),
                'keywords_count' => count($keywords),
                'response' => $e->response?->json(),
            ]);

            throw new SerpException(
                'Failed to fetch keyword data: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        } catch (SerpException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in getKeywordData', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new SerpException(
                'An unexpected error occurred: ' . $e->getMessage(),
                500,
                'UNEXPECTED_ERROR',
                $e
            );
        }
    }

    public function getSerpResults(
        string $keyword,
        string $languageCode = 'en',
        int $locationCode = 2840,
        array $options = []
    ): array {
        $cacheKey = $this->getCacheKey('serp_results', [
            'keyword' => $keyword,
            'language_code' => $languageCode,
            'location_code' => $locationCode,
            'options' => $options,
        ]);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $payload = [
            'q' => $keyword,
            'hl' => $languageCode,
            'gl' => $this->mapLocationCode($locationCode),
            'api_key' => $this->apiKey,
            ...$options,
        ];

        try {
            $httpResponse = $this->client()
                ->get($this->baseUrl . '/search', $payload);

            $response = $httpResponse->json();

            if (!$httpResponse->successful()) {
                $errorMessage = $response['error']['message'] ?? 'HTTP request failed';
                Log::error('Serp API HTTP error', [
                    'status' => $httpResponse->status(),
                    'error' => $errorMessage,
                    'response' => $response,
                ]);
                throw new SerpException(
                    'Serp API HTTP error: ' . $errorMessage,
                    $httpResponse->status(),
                    'API_ERROR'
                );
            }

            if (isset($response['error'])) {
                $errorMessage = $response['error']['message'] ?? 'Unknown error';
                Log::error('Serp API error', [
                    'error' => $errorMessage,
                    'code' => $response['error']['code'] ?? null,
                    'full_response' => $response,
                ]);
                throw new SerpException(
                    'Serp API error: ' . $errorMessage,
                    $response['error']['code'] ?? 500,
                    'API_ERROR'
                );
            }

            Cache::put($cacheKey, $response, now()->addSeconds($this->cacheTTL));

            return $response;
        } catch (RequestException $e) {
            Log::error('Serp API request failed', [
                'error' => $e->getMessage(),
                'keyword' => $keyword,
                'response' => $e->response?->json(),
            ]);

            throw new SerpException(
                'Failed to fetch SERP results: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        }
    }

    protected function getCacheKey(string $type, array $params): string
    {
        $key = sprintf(
            'serp:%s:%s',
            $type,
            md5(serialize($params))
        );

        return $key;
    }

    protected function mapLocationCode(int $locationCode): string
    {
        $locationCodeService = app(LocationCodeService::class);
        return $locationCodeService->mapLocationCodeToRegion($locationCode, 'us');
    }
}

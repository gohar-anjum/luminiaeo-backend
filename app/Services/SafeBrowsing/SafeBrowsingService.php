<?php

namespace App\Services\SafeBrowsing;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SafeBrowsingService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $timeout;
    protected int $cacheTtl;
    protected CacheRepository $cache;

    public function __construct(?CacheRepository $cache = null)
    {
        $this->baseUrl = config('services.safe_browsing.base_url');
        $this->apiKey = config('services.safe_browsing.api_key');
        $this->timeout = (int) config('services.safe_browsing.timeout', 15);
        $this->cacheTtl = (int) config('services.safe_browsing.cache_ttl', 604800);
        $this->cache = $cache ?? Cache::store(config('cache.default'));
    }

    public function enabled(): bool
    {
        return !empty($this->baseUrl) && !empty($this->apiKey);
    }

    public function checkUrl(string $url): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $cacheKey = sprintf('safe_browsing:%s', md5($url));

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($url) {
            $payload = $this->buildPayload($url);

            try {
                $response = Http::timeout($this->timeout)
                    ->acceptJson()
                    ->retry(2, 200)
                    ->post($this->baseUrl . '?key=' . $this->apiKey, $payload)
                    ->throw()
                    ->json();

                return $response;
            } catch (RequestException $e) {
                Log::error('Safe Browsing lookup failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'response' => $e->response?->json(),
                ]);

                return [];
            }
        });
    }

    protected function buildPayload(string $url): array
    {
        return [
            'client' => [
                'clientId' => 'luminiaeo-backend',
                'clientVersion' => '1.0.0',
            ],
            'threatInfo' => [
                'threatTypes' => [
                    'MALWARE',
                    'SOCIAL_ENGINEERING',
                    'UNWANTED_SOFTWARE',
                    'POTENTIALLY_HARMFUL_APPLICATION',
                ],
                'platformTypes' => ['ANY_PLATFORM'],
                'threatEntryTypes' => ['URL'],
                'threatEntries' => [
                    ['url' => $url],
                ],
            ],
        ];
    }

    public function extractSignals(array $raw): array
    {
        if (empty($raw['matches'])) {
            return [
                'status' => 'clean',
                'threats' => [],
                'checked_at' => now()->toIso8601String(),
            ];
        }

        $threats = array_map(function ($match) {
            return [
                'threatType' => $match['threatType'] ?? null,
                'platformType' => $match['platformType'] ?? null,
                'threatEntryType' => $match['threatEntryType'] ?? null,
                'threat' => $match['threat']['url'] ?? null,
            ];
        }, $raw['matches']);

        return [
            'status' => 'flagged',
            'threats' => $threats,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}


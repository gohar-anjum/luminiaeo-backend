<?php

namespace App\Services\Whois;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhoisLookupService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $timeout;
    protected int $cacheTtl;
    protected CacheRepository $cache;

    public function __construct(?CacheRepository $cache = null)
    {
        $this->baseUrl = rtrim(config('services.whoisxml.base_url', ''), '/');
        $this->apiKey = config('services.whoisxml.api_key');
        $this->timeout = (int) config('services.whoisxml.timeout', 20);
        $this->cacheTtl = (int) config('services.whoisxml.cache_ttl', 604800);
        $this->cache = $cache ?? Cache::store(config('cache.default'));
    }

    public function enabled(): bool
    {
        return !empty($this->baseUrl) && !empty($this->apiKey);
    }

    public function lookup(string $domain): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $domain = $this->sanitizeDomain($domain);
        $cacheKey = sprintf('whois:%s', md5($domain));

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($domain) {
            try {
                return Http::timeout($this->timeout)
                    ->acceptJson()
                    ->retry(2, 200)
                    ->get($this->baseUrl, [
                        'apiKey' => $this->apiKey,
                        'domainName' => $domain,
                        'outputFormat' => 'JSON',
                    ])
                    ->throw()
                    ->json();
            } catch (RequestException $e) {
                Log::error('WHOIS lookup failed', ['domain' => $domain, 'error' => $e->getMessage()]);
                return [];
            }
        });
    }

    public function extractSignals(array $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $record = Arr::get($raw, 'WhoisRecord', []);
        $registry = Arr::get($record, 'registryData', []);
        $domainName = Arr::get($record, 'domainName') ?? Arr::get($registry, 'domainName');
        $registrar = Arr::get($record, 'registrarName') ?? Arr::get($registry, 'registrarName');
        if ($registrar && mb_strlen($registrar) > 255) {
            $registrar = mb_substr($registrar, 0, 255);
        }
        $estimatedAge = Arr::get($record, 'estimatedDomainAge');
        $dataError = Arr::get($record, 'dataError') ?? Arr::get($registry, 'dataError');

        if (!$estimatedAge) {
            $createdAt = Arr::get($record, 'createdDateNormalized')
                ?? Arr::get($registry, 'createdDateNormalized')
                ?? Arr::get($registry, 'createdDate');

            $estimatedAge = $this->calculateDomainAge($createdAt);
        }

        return [
            'domain' => $domainName,
            'registrar' => $registrar,
            'domain_age_days' => $estimatedAge,
            'registered' => $dataError !== 'MISSING_WHOIS_DATA',
            'raw' => [
                'record' => $record,
                'registry' => $registry,
            ],
        ];
    }

    protected function sanitizeDomain(string $domain): string
    {
        $domain = Str::lower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = Str::before($domain, '/');
        return $domain;
    }

    protected function calculateDomainAge(?string $dateString): ?int
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            return Carbon::now()->diffInDays(Carbon::parse($dateString));
        } catch (\Throwable $e) {
            return null;
        }
    }
}


<?php

namespace App\Services\Pbn;

use App\Exceptions\PbnDetectorException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PbnDetectorService
{
    protected string $baseUrl;
    protected int $timeout;
    protected int $cacheTtl;
    protected ?string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.pbn_detector.base_url'), '/');
        $this->timeout = (int) config('services.pbn_detector.timeout', 30);
        $this->cacheTtl = (int) config('services.pbn_detector.cache_ttl', 86400);
        $this->secret = config('services.pbn_detector.secret');
    }

    public function enabled(): bool
    {
        return !empty($this->baseUrl);
    }

    public function analyze(string $domain, string $taskId, array $backlinks, array $summary = []): array
    {
        if (!$this->enabled()) {
            throw new PbnDetectorException('PBN detector service not configured', 503);
        }

        $payload = [
            'domain' => $domain,
            'task_id' => $taskId,
            'backlinks' => $backlinks,
            'summary' => $summary,
        ];
        $cacheKey = sprintf('pbn_detection:%s', md5($taskId . $domain));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $timestamp = now()->utc()->timestamp;
        $signature = $this->signPayload($payload, $timestamp);

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-PBN-Timestamp' => $timestamp,
                    'X-PBN-Signature' => $signature,
                ])
                ->retry(2, 200)
                ->post($this->baseUrl . '/detect', $payload)
                ->throw()
                ->json();

            Cache::put($cacheKey, $response, $this->cacheTtl);

            return $response;
        } catch (RequestException $e) {
            Log::error('PBN detector request failed', [
                'domain' => $domain,
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'response' => $e->response?->json(),
            ]);

            throw new PbnDetectorException(
                'Failed to analyze backlinks for PBN signals',
                $e->response?->status() ?? 500,
                $e
            );
        }
    }

    protected function signPayload(array $payload, int $timestamp): string
    {
        if (empty($this->secret)) {
            return '';
        }

        $data = $timestamp . json_encode($payload);
        return hash_hmac('sha256', $data, $this->secret);
    }
}


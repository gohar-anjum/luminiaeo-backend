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
            try {
                Log::error('PBN detector service not enabled', [
                    'base_url' => $this->baseUrl,
                    'domain' => $domain,
                    'task_id' => $taskId,
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }
            throw new PbnDetectorException('PBN detector service not configured', 503, 'SERVICE_NOT_CONFIGURED');
        }

        $payload = [
            'domain' => $domain,
            'task_id' => $taskId,
            'backlinks' => $backlinks,
            'summary' => $summary,
        ];
        
        $backlinksHash = md5(json_encode($backlinks));
        $cacheKey = sprintf('pbn_detection:%s', hash('sha256', $taskId . $domain . $backlinksHash . count($backlinks)));

        if (Cache::has($cacheKey)) {
            try {
                Log::info('PBN detection result retrieved from cache', [
                    'task_id' => $taskId,
                    'domain' => $domain,
                    'cache_key' => $cacheKey,
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }
            return Cache::get($cacheKey);
        }
        
        try {
            Log::info('Checking PBN detector service health', [
                'base_url' => $this->baseUrl,
                'health_endpoint' => $this->baseUrl . '/health',
            ]);
        } catch (\Exception $logError) {
            // Silently ignore logging errors
        }
        
        if (!$this->checkHealth()) {
            try {
                Log::error('PBN detector service health check failed', [
                    'base_url' => $this->baseUrl,
                    'domain' => $domain,
                    'task_id' => $taskId,
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }
            throw new PbnDetectorException('PBN detector service is not available', 503, 'SERVICE_UNAVAILABLE');
        }

        $timestamp = now()->utc()->timestamp;
        $signature = $this->signPayload($payload, $timestamp);

        try {
            Log::info('Sending request to PBN detector microservice', [
                'base_url' => $this->baseUrl,
                'endpoint' => $this->baseUrl . '/detect',
                'domain' => $domain,
                'task_id' => $taskId,
                'backlinks_count' => count($backlinks),
                'payload_size' => strlen(json_encode($payload)),
                'has_signature' => !empty($signature),
            ]);
        } catch (\Exception $logError) {
            // Silently ignore logging errors
        }

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

            try {
                Log::info('PBN detector microservice response received', [
                    'task_id' => $taskId,
                    'domain' => $domain,
                    'response_status' => 'success',
                    'response_keys' => array_keys($response),
                    'items_count' => count($response['items'] ?? []),
                    'has_summary' => isset($response['summary']),
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }

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
                'PBN_DETECTION_FAILED',
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

    protected function checkHealth(): bool
    {
        try {
            $response = Http::timeout(5)
                ->get($this->baseUrl . '/health');
            
            return $response->successful() && ($response->json()['status'] ?? null) === 'ok';
        } catch (\Exception $e) {
            Log::debug('PBN detector health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

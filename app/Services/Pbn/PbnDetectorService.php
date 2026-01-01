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

        Log::info('[PBN] Starting PBN detection analysis', [
            'task_id' => $taskId,
            'domain' => $domain,
            'backlinks_count' => count($backlinks),
            'has_summary' => !empty($summary),
            'cache_key' => $cacheKey,
            'base_url' => $this->baseUrl,
        ]);

        if (Cache::has($cacheKey)) {
            Log::info('[PBN] Cache hit - retrieving PBN detection result from cache', [
                'task_id' => $taskId,
                'domain' => $domain,
                'cache_key' => $cacheKey,
            ]);
            $cachedResponse = Cache::get($cacheKey);
            Log::info('[PBN] Cache retrieved successfully', [
                'task_id' => $taskId,
                'domain' => $domain,
                'cached_items_count' => count($cachedResponse['items'] ?? []),
                'cached_response_keys' => array_keys($cachedResponse),
            ]);
            return $cachedResponse;
        }

        Log::info('[PBN] Cache miss - will call microservice', [
            'task_id' => $taskId,
            'domain' => $domain,
            'cache_key' => $cacheKey,
        ]);
        
        Log::info('[PBN] Checking PBN detector service health', [
            'base_url' => $this->baseUrl,
            'health_endpoint' => $this->baseUrl . '/health',
            'task_id' => $taskId,
        ]);
        
        if (!$this->checkHealth()) {
            Log::error('[PBN] Health check failed - service unavailable', [
                'base_url' => $this->baseUrl,
                'domain' => $domain,
                'task_id' => $taskId,
            ]);
            throw new PbnDetectorException('PBN detector service is not available', 503, 'SERVICE_UNAVAILABLE');
        }

        Log::info('[PBN] Health check passed - service is available', [
            'base_url' => $this->baseUrl,
            'task_id' => $taskId,
        ]);

        $timestamp = now()->utc()->timestamp;
        $signature = $this->signPayload($payload, $timestamp);

        Log::info('[PBN] Preparing request payload', [
            'task_id' => $taskId,
            'domain' => $domain,
            'payload_domain' => $payload['domain'],
            'payload_task_id' => $payload['task_id'],
            'backlinks_count' => count($payload['backlinks']),
            'payload_size_bytes' => strlen(json_encode($payload)),
            'summary_keys' => array_keys($payload['summary'] ?? []),
            'timestamp' => $timestamp,
            'signature_generated' => !empty($signature),
            'signature_length' => strlen($signature),
        ]);

        Log::info('[PBN] Sending POST request to PBN detector microservice', [
            'base_url' => $this->baseUrl,
            'endpoint' => $this->baseUrl . '/detect',
            'method' => 'POST',
            'timeout' => $this->timeout,
            'task_id' => $taskId,
            'domain' => $domain,
            'backlinks_count' => count($backlinks),
            'headers' => [
                'X-PBN-Timestamp' => $timestamp,
                'X-PBN-Signature' => substr($signature, 0, 20) . '...', // Log partial signature for security
            ],
            'payload_sample' => [
                'domain' => $payload['domain'],
                'task_id' => $payload['task_id'],
                'backlinks_count' => count($payload['backlinks']),
                'first_backlink_sample' => count($payload['backlinks']) > 0 ? [
                    'source_url' => $payload['backlinks'][0]['source_url'] ?? null,
                    'domain_rank' => $payload['backlinks'][0]['domain_rank'] ?? null,
                ] : null,
            ],
        ]);

        try {
            $requestStartTime = microtime(true);
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
            $requestDuration = round((microtime(true) - $requestStartTime) * 1000, 2);

            Log::info('[PBN] Microservice request completed successfully', [
                'task_id' => $taskId,
                'domain' => $domain,
                'request_duration_ms' => $requestDuration,
                'response_received' => true,
            ]);

            Log::info('[PBN] Processing microservice response', [
                'task_id' => $taskId,
                'domain' => $domain,
                'response_keys' => array_keys($response),
                'items_count' => count($response['items'] ?? []),
                'has_summary' => isset($response['summary']),
                'has_meta' => isset($response['meta']),
                'response_summary' => $response['summary'] ?? null,
                'response_meta' => $response['meta'] ?? null,
            ]);

            if (isset($response['items']) && is_array($response['items'])) {
                Log::info('[PBN] Response items breakdown', [
                    'task_id' => $taskId,
                    'domain' => $domain,
                    'total_items' => count($response['items']),
                    'items_sample' => array_slice($response['items'], 0, 3, true), // First 3 items
                ]);
            }

            Log::info('[PBN] Storing response in cache', [
                'task_id' => $taskId,
                'domain' => $domain,
                'cache_key' => $cacheKey,
                'cache_ttl_seconds' => $this->cacheTtl,
                'cache_ttl_hours' => round($this->cacheTtl / 3600, 2),
            ]);

            Cache::put($cacheKey, $response, $this->cacheTtl);

            Log::info('[PBN] Cache stored successfully', [
                'task_id' => $taskId,
                'domain' => $domain,
                'cache_key' => $cacheKey,
            ]);

            Log::info('[PBN] PBN detection analysis completed successfully', [
                'task_id' => $taskId,
                'domain' => $domain,
                'total_items' => count($response['items'] ?? []),
                'request_duration_ms' => $requestDuration,
                'cached' => true,
            ]);

            return $response;
        } catch (RequestException $e) {
            $requestDuration = isset($requestStartTime) ? round((microtime(true) - $requestStartTime) * 1000, 2) : 0;
            
            Log::error('[PBN] Microservice request failed', [
                'task_id' => $taskId,
                'domain' => $domain,
                'endpoint' => $this->baseUrl . '/detect',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'http_status' => $e->response?->status(),
                'response_body' => $e->response?->json(),
                'response_text' => $e->response?->body(),
                'request_duration_ms' => $requestDuration,
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
            Log::info('[PBN] Health check - sending GET request', [
                'base_url' => $this->baseUrl,
                'health_endpoint' => $this->baseUrl . '/health',
                'timeout' => 5,
            ]);

            $healthStartTime = microtime(true);
            $response = Http::timeout(5)
                ->get($this->baseUrl . '/health');
            $healthDuration = round((microtime(true) - $healthStartTime) * 1000, 2);

            $isSuccessful = $response->successful();
            $responseData = $response->json();
            $status = $responseData['status'] ?? null;
            $isHealthy = $isSuccessful && $status === 'ok';

            Log::info('[PBN] Health check response received', [
                'base_url' => $this->baseUrl,
                'http_status' => $response->status(),
                'response_successful' => $isSuccessful,
                'response_status' => $status,
                'is_healthy' => $isHealthy,
                'response_data' => $responseData,
                'duration_ms' => $healthDuration,
            ]);
            
            return $isHealthy;
        } catch (\Exception $e) {
            Log::error('[PBN] Health check exception', [
                'base_url' => $this->baseUrl,
                'health_endpoint' => $this->baseUrl . '/health',
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);
            return false;
        }
    }
}

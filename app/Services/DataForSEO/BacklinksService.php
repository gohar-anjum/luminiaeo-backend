<?php

namespace App\Services\DataForSEO;

use App\Exceptions\DataForSEOException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BacklinksService
{
    protected string $baseUrl;
    protected string $login;
    protected string $password;
    protected int $summaryLimit;

    public function __construct()
    {
        // Initialize with empty strings to avoid type errors during autoload
        // Validation will happen when service is actually used
        $this->baseUrl = '';
        $this->login = '';
        $this->password = '';
        $this->summaryLimit = (int) config('services.dataforseo.backlinks.summary_limit', config('services.dataforseo.summary_limit', 100));
    }

    protected function ensureConfigured(): void
    {
        if (empty($this->baseUrl) || empty($this->login) || empty($this->password)) {
            $baseUrl = config('services.dataforseo.base_url');
            $login = config('services.dataforseo.login');
            $password = config('services.dataforseo.password');

            if (empty($baseUrl) || empty($login) || empty($password)) {
                throw new DataForSEOException('DataForSEO configuration is incomplete', 500, 'CONFIG_ERROR');
            }

            $this->baseUrl = (string) $baseUrl;
            $this->login = (string) $login;
            $this->password = (string) $password;
        }
    }

    protected function client()
    {
        $this->ensureConfigured();
        return Http::withBasicAuth($this->login, $this->password)
            ->acceptJson()
            ->baseUrl($this->baseUrl)
            ->timeout(config('services.dataforseo.timeout', 60))
            ->retry(3, 100);
    }

    public function submitBacklinksTask(string $domain, int $limit = null): array
    {
        if (empty($domain)) {
            throw new \InvalidArgumentException('Domain cannot be empty');
        }
        
        $defaultLimit = config('services.dataforseo.backlinks.default_limit', 100);
        $maxLimit = config('services.dataforseo.backlinks.max_limit', 1000);
        
        $limit = $limit ?? $defaultLimit;
        
        if ($limit < 1 || $limit > $maxLimit) {
            throw new \InvalidArgumentException("Limit must be between 1 and {$maxLimit}");
        }

        $domain = $this->normalizeDomain($domain);
        $payload = [[
            'target' => $domain,
            'mode' => 'as_is',
            'internal_list_limit' => $limit,
            'include_subdomains' => true,
            'filters' => ['dofollow', '=', true],
            'backlinks_status_type' => 'all',
            'limit' => $limit
        ]];

        try {
            $httpResponse = $this->client()->post('/backlinks/backlinks/live', $payload);
            $httpStatus = $httpResponse->status();
            $response = $httpResponse->json();

            // DataForSEO sometimes returns HTTP 500 even when API call is successful
            // Check the API status_code in the response body instead of relying on HTTP status
            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                // If HTTP status is not 2xx and we don't have valid response, throw
                if ($httpStatus >= 400 && $httpStatus < 600) {
                    Log::error('DataForSEO API request failed - invalid response structure', [
                        'domain' => $domain,
                        'http_status' => $httpStatus,
                        'response' => $response,
                    ]);
                    throw new DataForSEOException(
                        'Invalid API response: missing tasks (HTTP ' . $httpStatus . ')',
                        500,
                        'INVALID_RESPONSE'
                    );
                }
            }

            $task = $response['tasks'][0] ?? null;

            if (!$task) {
                throw new DataForSEOException('Invalid API response: missing tasks', 500, 'INVALID_RESPONSE');
            }

            // Check API status_code (not HTTP status) - 20000 means success
            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                Log::error('DataForSEO API error in response', [
                    'domain' => $domain,
                    'http_status' => $httpStatus,
                    'api_status_code' => $task['status_code'],
                    'api_status_message' => $task['status_message'] ?? 'Unknown error',
                ]);
                throw new DataForSEOException(
                    'DataForSEO API error: ' . ($task['status_message'] ?? 'Unknown error'),
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            // If we got here, the API call was successful (status_code: 20000)
            // Even if HTTP status was 500, we should proceed
            if ($httpStatus >= 400 && $httpStatus < 600) {
                Log::warning('DataForSEO returned HTTP error but API status_code indicates success', [
                    'domain' => $domain,
                    'http_status' => $httpStatus,
                    'api_status_code' => $task['status_code'] ?? 'N/A',
                    'task_id' => $task['id'] ?? 'N/A',
                ]);
            }

            if (!isset($task['id'])) {
                throw new DataForSEOException('Task ID missing in API response', 500, 'INVALID_RESPONSE');
            }

            return $task;
        } catch (RequestException $e) {
            // Only log and throw if we haven't already handled it above
            $responseBody = $e->response?->json();
            $apiStatusCode = $responseBody['tasks'][0]['status_code'] ?? null;
            
            // If API status_code is 20000, treat as success even if HTTP status is 500
            if ($apiStatusCode === 20000 && isset($responseBody['tasks'][0]['id'])) {
                Log::warning('DataForSEO HTTP error but API indicates success, proceeding', [
                    'domain' => $domain,
                    'http_status' => $e->response?->status(),
                    'api_status_code' => $apiStatusCode,
                ]);
                return $responseBody['tasks'][0];
            }
            
            Log::error('DataForSEO API request failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
                'http_status' => $e->response?->status(),
                'api_status_code' => $apiStatusCode,
            ]);
            throw new DataForSEOException('Failed to submit backlinks task: ' . $e->getMessage(), 500, 'API_REQUEST_FAILED', $e);
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in submitBacklinksTask', ['error' => $e->getMessage()]);
            throw new DataForSEOException('An unexpected error occurred: ' . $e->getMessage(), 500, 'UNEXPECTED_ERROR', $e);
        }
    }

    public function getBacklinksSummary(string $domain, ?int $limit = null): array
    {
        $limit ??= $this->summaryLimit;
        $domain = $this->normalizeDomain($domain);

        $payload = [
            [
                'target' => $domain,
                'mode' => 'as_is',
                'limit' => $limit,
                'filters' => ['dofollow', '=', true],
                'internal_list_limit' => $limit,
                'include_subdomains' => true,
            ],
        ];

        try {
            $httpResponse = $this->client()->post('/backlinks/summary/live', $payload);
            $httpStatus = $httpResponse->status();
            $response = $httpResponse->json();

            $task = $response['tasks'][0] ?? [];

            // Check API status_code (not HTTP status) - 20000 means success
            if (($task['status_code'] ?? 0) !== 20000) {
                throw new DataForSEOException(
                    $task['status_message'] ?? 'Failed to fetch backlink summary',
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            // If HTTP status was error but API status_code is 20000, log warning
            if ($httpStatus >= 400 && $httpStatus < 600) {
                Log::warning('DataForSEO summary returned HTTP error but API status_code indicates success', [
                    'domain' => $domain,
                    'http_status' => $httpStatus,
                    'api_status_code' => $task['status_code'] ?? 'N/A',
                ]);
            }

            return $task['result'][0] ?? [];
        } catch (RequestException $e) {
            $responseBody = $e->response?->json();
            $apiStatusCode = $responseBody['tasks'][0]['status_code'] ?? null;
            
            // If API status_code is 20000, treat as success even if HTTP status is 500
            if ($apiStatusCode === 20000 && isset($responseBody['tasks'][0]['result'])) {
                Log::warning('DataForSEO summary HTTP error but API indicates success, proceeding', [
                    'domain' => $domain,
                    'http_status' => $e->response?->status(),
                    'api_status_code' => $apiStatusCode,
                ]);
                return $responseBody['tasks'][0]['result'][0] ?? [];
            }
            
            Log::error('Failed to fetch backlink summary', [
                'domain' => $domain,
                'error' => $e->getMessage(),
                'http_status' => $e->response?->status(),
                'api_status_code' => $apiStatusCode,
            ]);
            throw new DataForSEOException('Failed to fetch backlink summary: ' . $e->getMessage(), $e->response?->status() ?? 500, 'API_REQUEST_FAILED', $e);
        }
    }

    public function getTaskStatus(string $taskId): array
    {
        try {
            $httpResponse = $this->client()->post('/backlinks/task_get', ['task_id' => $taskId]);
            $httpStatus = $httpResponse->status();
            $response = $httpResponse->json();

            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                throw new DataForSEOException('Invalid API response: missing tasks', 500, 'INVALID_RESPONSE');
            }

            $task = $response['tasks'][0];

            // Check API status_code (not HTTP status) - 20000 means success
            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                throw new DataForSEOException(
                    'DataForSEO API error: ' . ($task['status_message'] ?? 'Unknown error'),
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            // If HTTP status was error but API status_code is 20000, log warning
            if ($httpStatus >= 400 && $httpStatus < 600) {
                Log::warning('DataForSEO task status returned HTTP error but API status_code indicates success', [
                    'task_id' => $taskId,
                    'http_status' => $httpStatus,
                    'api_status_code' => $task['status_code'] ?? 'N/A',
                ]);
            }

            return $task;
        } catch (RequestException $e) {
            $responseBody = $e->response?->json();
            $apiStatusCode = $responseBody['tasks'][0]['status_code'] ?? null;
            
            // If API status_code is 20000, treat as success even if HTTP status is 500
            if ($apiStatusCode === 20000 && isset($responseBody['tasks'][0])) {
                Log::warning('DataForSEO task status HTTP error but API indicates success, proceeding', [
                    'task_id' => $taskId,
                    'http_status' => $e->response?->status(),
                    'api_status_code' => $apiStatusCode,
                ]);
                return $responseBody['tasks'][0];
            }
            
            Log::error('DataForSEO API request failed', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'http_status' => $e->response?->status(),
                'api_status_code' => $apiStatusCode,
            ]);
            throw new DataForSEOException('Failed to get task status: ' . $e->getMessage(), 500, 'API_REQUEST_FAILED', $e);
        }
    }

    public function getBacklinksResults(string $taskId): array
    {
        try {
            $task = $this->getTaskStatus($taskId);

            if (!isset($task['result']) || !is_array($task['result']) || empty($task['result'])) {
                return ['pending' => true, 'task' => $task];
            }

            if (!isset($task['result'][0]['items']) || !is_array($task['result'][0]['items'])) {
                return [];
            }

            return $task['result'][0]['items'];
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in getBacklinksResults', ['error' => $e->getMessage()]);
            throw new DataForSEOException('An unexpected error occurred: ' . $e->getMessage(), 500, 'UNEXPECTED_ERROR', $e);
        }
    }

    protected function normalizeDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        return !str_starts_with($domain, 'http') ? 'https://' . $domain : $domain;
    }
}

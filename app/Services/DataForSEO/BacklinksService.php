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
        $this->baseUrl = config('services.dataforseo.base_url');
        $this->login = config('services.dataforseo.login');
        $this->password = config('services.dataforseo.password');
        $this->summaryLimit = (int) config('services.dataforseo.summary_limit', 100);

        // Validate configuration
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
            ->timeout(config('services.dataforseo.timeout', 60))
            ->retry(3, 100); // Retry 3 times with 100ms delay
    }

    /**
     * Submit a backlinks task to the live endpoint (immediate response).
     *
     * @param string $domain Domain to analyze
     * @param int $limit Maximum number of backlinks to retrieve (1-1000)
     * @return array Task information including task_id and result payload
     * @throws DataForSEOException
     */
    public function submitBacklinksTask(string $domain, int $limit = 100): array
    {
        // Validate input
        if (empty($domain)) {
            throw new \InvalidArgumentException('Domain cannot be empty');
        }

        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Limit must be between 1 and 1000');
        }

        // Normalize domain
        $domain = $this->normalizeDomain($domain);

        $payload = [
            [
                'target' => $domain,
                "mode"=> "as_is",
                'internal_list_limit' => $limit,
                'include_subdomains' => true,
                'filters' => ['dofollow', '=', true],
                'backlinks_status_type' => 'all',
                'limit' => 10,
            ]
        ];

        try {
            Log::info('Submitting backlinks task to DataForSEO API', [
                'domain' => $domain,
                'limit' => $limit,
            ]);

            $response = $this->client()
                ->post('/backlinks/backlinks/live', $payload)
                ->throw()
                ->json();

            Log::info('Response from DataForSEO API', ['response' => $response]);
            // Validate response structure
            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                Log::error('Invalid API response structure: missing tasks', ['response' => $response]);
                throw new DataForSEOException(
                    'Invalid API response: missing tasks',
                    500,
                    'INVALID_RESPONSE'
                );
            }

            $task = $response['tasks'][0];

            // Check for API errors
            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                $errorMessage = $task['status_message'] ?? 'Unknown error';
                Log::error('DataForSEO API error', [
                    'status_code' => $task['status_code'],
                    'status_message' => $errorMessage,
                    'domain' => $domain,
                ]);
                throw new DataForSEOException(
                    'DataForSEO API error: ' . $errorMessage,
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            // Validate task ID
            if (!isset($task['id'])) {
                Log::error('Task ID missing in API response', ['task' => $task]);
                throw new DataForSEOException(
                    'Task ID missing in API response',
                    500,
                    'INVALID_RESPONSE'
                );
            }

            Log::info('Successfully submitted backlinks task', [
                'domain' => $domain,
                'task_id' => $task['id'],
            ]);

            return $task;
        } catch (RequestException $e) {
            Log::error('DataForSEO API request failed', [
                'error' => $e->getMessage(),
                'domain' => $domain,
                'response' => $e->response?->json(),
            ]);

            throw new DataForSEOException(
                'Failed to submit backlinks task: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in submitBacklinksTask', [
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
            $response = $this->client()
                ->post('/backlinks/summary/live', $payload)
                ->throw()
                ->json();

            $task = $response['tasks'][0] ?? [];

            if (($task['status_code'] ?? 0) !== 20000) {
                throw new DataForSEOException(
                    $task['status_message'] ?? 'Failed to fetch backlink summary',
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            return $task['result'][0] ?? [];
        } catch (RequestException $e) {
            Log::error('Failed to fetch backlink summary', [
                'domain' => $domain,
                'error' => $e->getMessage(),
                'response' => $e->response?->json(),
            ]);

            throw new DataForSEOException(
                'Failed to fetch backlink summary: ' . $e->getMessage(),
                $e->response?->status() ?? 500,
                'API_REQUEST_FAILED',
                $e
            );
        }
    }

    /**
     * Get task status
     *
     * @param string $taskId Task ID
     * @return array Task status information
     * @throws DataForSEOException
     */
    public function getTaskStatus(string $taskId): array
    {
        try {
            $response = $this->client()
                ->post('/backlinks/task_get', ['task_id' => $taskId])
                ->throw()
                ->json();

            // Validate response structure
            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                Log::error('Invalid API response structure: missing tasks', ['response' => $response]);
                throw new DataForSEOException(
                    'Invalid API response: missing tasks',
                    500,
                    'INVALID_RESPONSE'
                );
            }

            $task = $response['tasks'][0];

            // Check for API errors
            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                $errorMessage = $task['status_message'] ?? 'Unknown error';
                Log::error('DataForSEO API error', [
                    'status_code' => $task['status_code'],
                    'status_message' => $errorMessage,
                    'task_id' => $taskId,
                ]);
                throw new DataForSEOException(
                    'DataForSEO API error: ' . $errorMessage,
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            return $task;
        } catch (RequestException $e) {
            Log::error('DataForSEO API request failed', [
                'error' => $e->getMessage(),
                'task_id' => $taskId,
                'response' => $e->response?->json(),
            ]);

            throw new DataForSEOException(
                'Failed to get task status: ' . $e->getMessage(),
                500,
                'API_REQUEST_FAILED',
                $e
            );
        }
    }

    /**
     * Retrieve backlinks results (after task completion)
     *
     * @param string $taskId Task ID
     * @return array Array of backlink items
     * @throws DataForSEOException
     */
    public function getBacklinksResults(string $taskId): array
    {
        try {
            Log::info('Fetching backlinks results from DataForSEO API', [
                'task_id' => $taskId,
            ]);

            $task = $this->getTaskStatus($taskId);

            // Check if task is completed
            if (!isset($task['result']) || !is_array($task['result']) || empty($task['result'])) {
                Log::info('Task not completed yet', [
                    'task_id' => $taskId,
                    'task_status' => $task['status_code'] ?? 'unknown',
                ]);
                return ['pending' => true, 'task' => $task];
            }

            // Validate result structure
            if (!isset($task['result'][0]['items']) || !is_array($task['result'][0]['items'])) {
                Log::warning('Invalid result structure: missing items', [
                    'task_id' => $taskId,
                    'task' => $task,
                ]);
                return [];
            }

            $items = $task['result'][0]['items'];

            Log::info('Successfully fetched backlinks results', [
                'task_id' => $taskId,
                'items_count' => count($items),
            ]);

            return $items;
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error in getBacklinksResults', [
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
     * Normalize domain URL
     *
     * @param string $domain Domain URL
     * @return string Normalized domain URL
     */
    protected function normalizeDomain(string $domain): string
    {
        // Remove protocol if present
        $domain = preg_replace('#^https?://#', '', $domain);

        // Remove trailing slash
        $domain = rtrim($domain, '/');

        // Add https:// if no protocol
        if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
            $domain = 'https://' . $domain;
        }

        return $domain;
    }
}

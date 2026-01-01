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

            if (!isset($response['tasks']) || !is_array($response['tasks']) || empty($response['tasks'])) {
                if ($httpStatus >= 400 && $httpStatus < 600) {
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

            if (isset($task['status_code']) && $task['status_code'] !== 20000) {
                throw new DataForSEOException(
                    'DataForSEO API error: ' . ($task['status_message'] ?? 'Unknown error'),
                    $task['status_code'] ?? 500,
                    'API_ERROR'
                );
            }

            if (!isset($task['id'])) {
                throw new DataForSEOException('Task ID missing in API response', 500, 'INVALID_RESPONSE');
            }

            return $task;
        } catch (RequestException $e) {
            $responseBody = $e->response?->json();
            $apiStatusCode = $responseBody['tasks'][0]['status_code'] ?? null;
            
            if ($apiStatusCode === 20000 && isset($responseBody['tasks'][0]['id'])) {
                return $responseBody['tasks'][0];
            }
            
            throw new DataForSEOException('Failed to submit backlinks task: ' . $e->getMessage(), 500, 'API_REQUEST_FAILED', $e);
        } catch (DataForSEOException $e) {
            throw $e;
        } catch (\Exception $e) {
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

            return $task['result'][0] ?? [];
        } catch (RequestException $e) {
            $responseBody = $e->response?->json();
            $apiStatusCode = $responseBody['tasks'][0]['status_code'] ?? null;
            
            if ($apiStatusCode === 20000 && isset($responseBody['tasks'][0]['result'])) {
                return $responseBody['tasks'][0]['result'][0] ?? [];
            }
            
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

            return $task;
        } catch (RequestException $e) {
            $responseBody = $e->response?->json();
            $apiStatusCode = $responseBody['tasks'][0]['status_code'] ?? null;
            
            if ($apiStatusCode === 20000 && isset($responseBody['tasks'][0])) {
                return $responseBody['tasks'][0];
            }
            
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

<?php

namespace App\Services\DataForSEO;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;

class BacklinksService
{
    protected string $baseUrl;
    protected string $login;
    protected string $password;

    public function __construct()
    {
        $this->baseUrl  = config('services.dataforseo.base_url');
        $this->login    = config('services.dataforseo.login');
        $this->password = config('services.dataforseo.password');
    }

    protected function client()
    {
        return Http::withBasicAuth($this->login, $this->password)
            ->acceptJson()
            ->baseUrl($this->baseUrl)
            ->timeout(60);
    }

    /**
     * Submit a backlinks task (asynchronous call)
     */
    public function submitBacklinksTask(string $domain, int $limit = 100): array
    {
        $payload = [
            'tasks' => [
                [
                    'target' => $domain,
                    'limit'  => $limit,
                ]
            ]
        ];

        try {
            $response = $this->client()
                ->post('/backlinks/task_post', $payload)
                ->throw()
                ->json();

            return $response['tasks'][0] ?? [];
        } catch (RequestException $e) {
            report($e);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Retrieve backlinks results (after task completion)
     */
    public function getBacklinksResults(string $taskId): array
    {
        try {
            $response = $this->client()
                ->post('/backlinks/task_get', ['task_id' => $taskId])
                ->throw()
                ->json();

            return $response['tasks'][0]['result'][0]['items'] ?? [];
        } catch (RequestException $e) {
            report($e);
            return ['error' => true, 'message' => $e->getMessage()];
        }
    }
}

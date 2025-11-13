<?php

namespace App\Repositories\DataForSEO;

use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Services\DataForSEO\BacklinksService;

class BacklinksRepository implements BacklinksRepositoryInterface
{
    protected BacklinksService $service;

    public function __construct(BacklinksService $service)
    {
        $this->service = $service;
    }

    public function createTask(string $domain, int $limit = 100): array
    {
        return $this->service->submitBacklinksTask($domain, $limit);
    }

    public function fetchResults(string $taskId): array
    {
        return $this->service->getBacklinksResults($taskId);
    }
}

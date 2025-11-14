<?php

namespace App\Interfaces\DataForSEO;

use App\Models\SeoTask;

interface BacklinksRepositoryInterface
{
    public function createTask(string $domain, int $limit = 100): SeoTask;
    public function fetchResults(string $taskId): array;
    public function getTaskStatus(string $taskId): ?SeoTask;
    public function updateTaskStatus(string $taskId, string $status, array $result = null, string $errorMessage = null): bool;
    public function getHarmfulBacklinks(string $domain, array $riskLevels = ['high', 'critical']): array;
}

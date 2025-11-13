<?php

namespace App\Interfaces\DataForSEO;

interface BacklinksRepositoryInterface
{
    public function createTask(string $domain, int $limit = 100): array;
    public function fetchResults(string $taskId): array;
}

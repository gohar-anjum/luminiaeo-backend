<?php

namespace App\Interfaces;

use App\Models\Keyword;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface KeywordRepositoryInterface
{
    public function all(): Collection;
    public function find($id): Keyword;
    public function create(array $data): Keyword;
    public function update($id, array $data): Keyword;
    public function delete($id): bool;
    public function findByResearchJob(int $jobId): Collection;
    public function findByCluster(int $clusterId): Collection;
    public function findBySource(string $source): Collection;
    public function findByIntentCategory(string $category): Collection;
    public function getHighVisibilityKeywords(float $minScore = 70.0): Collection;
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    public function search(string $query): Collection;
}

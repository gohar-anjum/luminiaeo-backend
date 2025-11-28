<?php

namespace App\Repositories;

use App\Interfaces\KeywordRepositoryInterface;
use App\Models\Keyword;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class KeywordRepository implements KeywordRepositoryInterface
{
    public function all(): Collection
    {
        return Keyword::all();
    }

    public function find($id): Keyword
    {
        return Keyword::findOrFail($id);
    }

    public function create(array $data): Keyword
    {
        return Keyword::create($data);
    }

    public function update($id, array $data): Keyword
    {
        $keyword = Keyword::findOrFail($id);
        $keyword->update($data);
        return $keyword;
    }

    public function delete($id): bool
    {
        $keyword = Keyword::findOrFail($id);
        $keyword->delete();
        return true;
    }

    public function findByResearchJob(int $jobId): Collection
    {
        return Keyword::where('keyword_research_job_id', $jobId)->get();
    }

    public function findByCluster(int $clusterId): Collection
    {
        return Keyword::where('keyword_cluster_id', $clusterId)->get();
    }

    public function findBySource(string $source): Collection
    {
        return Keyword::fromSource($source)->get();
    }

    public function findByIntentCategory(string $category): Collection
    {
        return Keyword::withIntentCategory($category)->get();
    }

    public function getHighVisibilityKeywords(float $minScore = 70.0): Collection
    {
        return Keyword::highVisibility($minScore)->get();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Keyword::paginate($perPage);
    }

    public function search(string $query): Collection
    {
        return Keyword::where('keyword', 'like', "%{$query}%")->get();
    }
}

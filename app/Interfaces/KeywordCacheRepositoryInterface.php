<?php

namespace App\Interfaces;

use App\Models\KeywordCache;
use Illuminate\Database\Eloquent\Collection;

interface KeywordCacheRepositoryInterface
{
    public function find(string $keyword, string $languageCode = 'en', int $locationCode = 2840): ?KeywordCache;
    public function findValid(string $keyword, string $languageCode = 'en', int $locationCode = 2840): ?KeywordCache;
    public function create(array $data): KeywordCache;
    public function update(string $keyword, string $languageCode, int $locationCode, array $data): KeywordCache;
    public function delete(string $keyword, string $languageCode, int $locationCode): bool;
    public function deleteExpired(): int;
    public function findByCluster(string $clusterId): Collection;
    public function bulkCreate(array $keywords): int;
    public function bulkUpdate(array $keywords): int;
    public function getExpiringSoon(int $days = 7): Collection;
    public function findByTopic(string $topic, string $languageCode = 'en', int $locationCode = 2840): Collection;
}

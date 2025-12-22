<?php

namespace App\Repositories;

use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Models\KeywordCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KeywordCacheRepository implements KeywordCacheRepositoryInterface
{
    public function find(string $keyword, string $languageCode = 'en', int $locationCode = 2840): ?KeywordCache
    {
        return KeywordCache::byKeyword($keyword, $languageCode, $locationCode)->first();
    }

    public function findValid(string $keyword, string $languageCode = 'en', int $locationCode = 2840): ?KeywordCache
    {
        return KeywordCache::byKeyword($keyword, $languageCode, $locationCode)
            ->valid()
            ->first();
    }

    public function create(array $data): KeywordCache
    {
        if (!isset($data['expires_at'])) {
            $data['expires_at'] = Carbon::now()->addDays(30);
        }

        if (!isset($data['cached_at'])) {
            $data['cached_at'] = Carbon::now();
        }

        return KeywordCache::create($data);
    }

    public function update(string $keyword, string $languageCode, int $locationCode, array $data): KeywordCache
    {
        $cache = $this->find($keyword, $languageCode, $locationCode);

        if (!$cache) {
            throw new \RuntimeException("Keyword cache not found for keyword: {$keyword}");
        }

        if (!isset($data['expires_at'])) {
            $data['expires_at'] = Carbon::now()->addDays(30);
        }

        $cache->update($data);

        return $cache->fresh();
    }

    public function delete(string $keyword, string $languageCode, int $locationCode): bool
    {
        $cache = $this->find($keyword, $languageCode, $locationCode);

        if (!$cache) {
            return false;
        }

        return $cache->delete();
    }

    public function deleteExpired(): int
    {
        $deleted = KeywordCache::expired()->delete();

        Log::info('Deleted expired keyword cache entries', [
            'count' => $deleted,
        ]);

        return $deleted;
    }

    public function findByCluster(string $clusterId): Collection
    {
        return KeywordCache::where('cluster_id', $clusterId)->get();
    }

    public function bulkCreate(array $keywords): int
    {
        $now = Carbon::now();
        $expiresAt = $now->copy()->addDays(30);

        $data = array_map(function ($keyword) use ($now, $expiresAt) {
            return array_merge([
                'cached_at' => $now,
                'expires_at' => $expiresAt,
            ], $keyword);
        }, $keywords);

        try {
            DB::beginTransaction();

            $inserted = 0;
            foreach ($data as $item) {
                try {
                    KeywordCache::create($item);
                    $inserted++;
                } catch (\Exception $e) {

                    Log::warning('Failed to insert keyword cache entry', [
                        'keyword' => $item['keyword'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DB::commit();

            Log::info('Bulk created keyword cache entries', [
                'total' => count($keywords),
                'inserted' => $inserted,
            ]);

            return $inserted;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk create keyword cache entries', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function bulkUpdate(array $keywords): int
    {
        $updated = 0;

        try {
            DB::beginTransaction();

            foreach ($keywords as $keywordData) {
                $keyword = $keywordData['keyword'] ?? null;
                $languageCode = $keywordData['language_code'] ?? 'en';
                $locationCode = $keywordData['location_code'] ?? 2840;

                if (!$keyword) {
                    continue;
                }

                $cache = $this->find($keyword, $languageCode, $locationCode);

                if ($cache) {

                    $keywordData['expires_at'] = Carbon::now()->addDays(30);
                    $cache->update($keywordData);
                    $updated++;
                } else {

                    $this->create($keywordData);
                    $updated++;
                }
            }

            DB::commit();

            Log::info('Bulk updated keyword cache entries', [
                'total' => count($keywords),
                'updated' => $updated,
            ]);

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk update keyword cache entries', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getExpiringSoon(int $days = 7): Collection
    {
        $threshold = Carbon::now()->addDays($days);

        return KeywordCache::where('expires_at', '<=', $threshold)
            ->where('expires_at', '>', now())
            ->get();
    }
}

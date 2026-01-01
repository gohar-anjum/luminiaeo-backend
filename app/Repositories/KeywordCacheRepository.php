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
        return KeywordCache::expired()->delete();
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
                }
            }

            DB::commit();

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
        if (empty($keywords)) {
            return 0;
        }

        try {
            DB::beginTransaction();

            $now = Carbon::now();
            $expiresAt = $now->copy()->addDays(30);

            $preparedData = [];
            $lookupKeys = [];
            
            foreach ($keywords as $keywordData) {
                $keyword = $keywordData['keyword'] ?? null;
                if (!$keyword) {
                    continue;
                }

                $languageCode = $keywordData['language_code'] ?? 'en';
                $locationCode = $keywordData['location_code'] ?? 2840;
                
                $preparedData[] = array_merge($keywordData, [
                    'expires_at' => $expiresAt,
                    'cached_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                
                $lookupKeys[] = [
                    'keyword' => $keyword,
                    'language_code' => $languageCode,
                    'location_code' => $locationCode,
                ];
            }

            if (empty($preparedData)) {
                DB::commit();
                return 0;
            }

            $existingRecords = collect();
            if (!empty($lookupKeys)) {
                $query = KeywordCache::query();
                $first = true;
                foreach ($lookupKeys as $key) {
                    if ($first) {
                        $query->where(function ($q) use ($key) {
                            $q->where('keyword', $key['keyword'])
                              ->where('language_code', $key['language_code'])
                              ->where('location_code', $key['location_code']);
                        });
                        $first = false;
                    } else {
                        $query->orWhere(function ($q) use ($key) {
                            $q->where('keyword', $key['keyword'])
                              ->where('language_code', $key['language_code'])
                              ->where('location_code', $key['location_code']);
                        });
                    }
                }
                $existingRecords = $query->get()->keyBy(function ($item) {
                    return "{$item->keyword}:{$item->language_code}:{$item->location_code}";
                });
            }

            $toInsert = [];
            $toUpdate = [];

            foreach ($preparedData as $data) {
                $lookupKey = "{$data['keyword']}:{$data['language_code']}:{$data['location_code']}";
                
                // JSON encode array fields for direct insert (insert() doesn't use model casts)
                if (isset($data['metadata']) && is_array($data['metadata'])) {
                    $data['metadata'] = json_encode($data['metadata']);
                }
                if (isset($data['serp_features']) && is_array($data['serp_features'])) {
                    $data['serp_features'] = json_encode($data['serp_features']);
                }
                if (isset($data['related_keywords']) && is_array($data['related_keywords'])) {
                    $data['related_keywords'] = json_encode($data['related_keywords']);
                }
                if (isset($data['trends']) && is_array($data['trends'])) {
                    $data['trends'] = json_encode($data['trends']);
                }
                if (isset($data['cluster_data']) && is_array($data['cluster_data'])) {
                    $data['cluster_data'] = json_encode($data['cluster_data']);
                }
                
                if (isset($existingRecords[$lookupKey])) {
                    unset($data['created_at']);
                    $toUpdate[] = [
                        'id' => $existingRecords[$lookupKey]->id,
                        'data' => $data,
                    ];
                } else {
                    $toInsert[] = $data;
                }
            }

            if (!empty($toInsert)) {
                KeywordCache::insert($toInsert);
            }

            if (!empty($toUpdate)) {
                foreach (array_chunk($toUpdate, 100) as $chunk) {
                    foreach ($chunk as $update) {
                        $updateData = $update['data'];
                        // JSON encode array fields for direct update
                        if (isset($updateData['metadata']) && is_array($updateData['metadata'])) {
                            $updateData['metadata'] = json_encode($updateData['metadata']);
                        }
                        if (isset($updateData['serp_features']) && is_array($updateData['serp_features'])) {
                            $updateData['serp_features'] = json_encode($updateData['serp_features']);
                        }
                        if (isset($updateData['related_keywords']) && is_array($updateData['related_keywords'])) {
                            $updateData['related_keywords'] = json_encode($updateData['related_keywords']);
                        }
                        if (isset($updateData['trends']) && is_array($updateData['trends'])) {
                            $updateData['trends'] = json_encode($updateData['trends']);
                        }
                        if (isset($updateData['cluster_data']) && is_array($updateData['cluster_data'])) {
                            $updateData['cluster_data'] = json_encode($updateData['cluster_data']);
                        }
                        KeywordCache::where('id', $update['id'])->update($updateData);
                    }
                }
            }

            DB::commit();

            return count($toInsert) + count($toUpdate);
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

    public function findByTopic(string $topic, string $languageCode = 'en', int $locationCode = 2840): Collection
    {
        $normalizedTopic = strtolower(trim($topic));
        
        return KeywordCache::where('language_code', $languageCode)
            ->where('location_code', $locationCode)
            ->where('expires_at', '>', now())
            ->where(function($query) use ($normalizedTopic) {
                $query->whereJsonContains('metadata->topic', $normalizedTopic)
                      ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.topic'))) = ?", [$normalizedTopic])
                      ->orWhereJsonContains('metadata->topics', $normalizedTopic);
            })
            ->get();
    }
}

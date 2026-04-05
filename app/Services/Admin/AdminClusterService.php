<?php

namespace App\Services\Admin;

use App\Models\KeywordClusterSnapshot;
use App\Support\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Keyword cluster snapshots and related history.
 */
class AdminClusterService
{
    /**
     * @return LengthAwarePaginator<int, KeywordClusterSnapshot>
     */
    public function paginateClusters(int $perPage = 30): LengthAwarePaginator
    {
        return KeywordClusterSnapshot::query()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCluster(KeywordClusterSnapshot $s): array
    {
        return [
            'id' => $s->id,
            'cache_key' => $s->cache_key,
            'keyword' => $s->keyword,
            'language_code' => $s->language_code,
            'location_code' => $s->location_code,
            'expires_at' => Iso8601::utcZ($s->expires_at),
            'status' => $this->snapshotStatus($s),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function snapshotsForCluster(KeywordClusterSnapshot $anchor): array
    {
        $clusterId = $anchor->id;

        return KeywordClusterSnapshot::query()
            ->where('cache_key', $anchor->cache_key)
            ->orderByDesc('id')
            ->get()
            ->map(fn (KeywordClusterSnapshot $s) => $this->serializeSnapshotRow($s, $clusterId))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSnapshotRow(KeywordClusterSnapshot $s, int $clusterId): array
    {
        return [
            'id' => $s->id,
            'cluster_id' => $clusterId,
            'expires_at' => Iso8601::utcZ($s->expires_at),
            'status' => $this->snapshotStatus($s),
        ];
    }

    public function snapshotStatus(KeywordClusterSnapshot $s): string
    {
        $expires = $s->expires_at;
        if ($expires === null) {
            return 'expired';
        }

        if ($expires->lte(now())) {
            return 'expired';
        }

        if ($expires->lte(now()->addDays(7))) {
            return 'expiring';
        }

        return 'valid';
    }
}

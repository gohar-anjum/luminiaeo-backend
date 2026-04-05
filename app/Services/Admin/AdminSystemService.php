<?php

namespace App\Services\Admin;

use App\Models\AdminAnnouncement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Cache flush, health checks, announcements.
 */
class AdminSystemService
{
    public function clearApplicationCache(): void
    {
        Cache::forget(config('admin.stats_cache_key'));
        Cache::forget(config('admin.charts_cache_key'));
        Artisan::call('cache:clear');
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $dbOk = true;
        $dbMessage = 'ok';
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbOk = false;
            $dbMessage = 'unavailable';
        }

        $redisOk = true;
        $redisMessage = 'ok';
        try {
            Redis::connection()->ping();
        } catch (\Throwable $e) {
            $redisOk = false;
            $redisMessage = 'unavailable';
        }

        return [
            'status' => $dbOk && $redisOk ? 'healthy' : 'degraded',
            'database' => $dbMessage,
            'redis' => $redisMessage,
            'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createAnnouncement(string $title, string $body, ?int $authorId = null): array
    {
        $a = AdminAnnouncement::create([
            'title' => $title,
            'body' => $body,
            'created_by' => $authorId,
        ]);

        return [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'created_by' => $a->created_by,
            'created_at' => $a->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

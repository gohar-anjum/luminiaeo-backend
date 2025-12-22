<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CacheService
{
    public function remember(string $key, int $ttl, callable $callback, string $tag = null)
    {
        try {
            if ($tag) {
                return Cache::tags([$tag])->remember($key, $ttl, $callback);
            }
            return Cache::remember($key, $ttl, $callback);
        } catch (\Exception $e) {
            Log::warning('Cache operation failed, using fallback', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    public function forget(string $key, string $tag = null): bool
    {
        try {
            if ($tag) {
                Cache::tags([$tag])->forget($key);
                return true;
            }
            return Cache::forget($key);
        } catch (\Exception $e) {
            Log::warning('Cache forget failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function forgetByPattern(string $pattern): int
    {
        try {
            if (config('cache.default') !== 'redis') {
                return 0;
            }

            $keys = Redis::keys($pattern);
            if (empty($keys)) {
                return 0;
            }

            return Redis::del($keys);
        } catch (\Exception $e) {
            Log::warning('Cache pattern forget failed', [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function clear(): bool
    {
        try {
            Cache::flush();
            return true;
        } catch (\Exception $e) {
            Log::error('Cache clear failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getStats(): array
    {
        try {
            if (config('cache.default') === 'redis') {
                $info = Redis::info('stats');
                return [
                    'driver' => 'redis',
                    'hits' => $info['keyspace_hits'] ?? 0,
                    'misses' => $info['keyspace_misses'] ?? 0,
                    'keys' => count(Redis::keys('*')),
                ];
            }

            return [
                'driver' => config('cache.default'),
                'stats' => 'Not available for this driver',
            ];
        } catch (\Exception $e) {
            Log::warning('Cache stats failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    public function key(string $prefix, ...$parts): string
    {
        $key = $prefix . ':' . implode(':', array_filter($parts));
        return str_replace([' ', '/', '\\'], '_', $key);
    }
}

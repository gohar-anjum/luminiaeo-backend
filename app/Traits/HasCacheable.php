<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait HasCacheable
{
    protected function getCacheKeyPrefix(): string
    {
        return strtolower(class_basename($this)) . ':';
    }

    protected function getCacheKey(string $identifier): string
    {
        return $this->getCacheKeyPrefix() . $identifier;
    }

    protected function remember(string $key, int $ttl, callable $callback)
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (\Exception $e) {
            Log::warning('Cache operation failed, using fallback', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    protected function forgetCache(string $pattern): void
    {
        try {
            if (config('cache.default') === 'redis') {
                $keys = Cache::getRedis()->keys($pattern);
                if (!empty($keys)) {
                    Cache::getRedis()->del($keys);
                }
            } else {
                Cache::forget($pattern);
            }
        } catch (\Exception $e) {
            Log::warning('Cache forget failed', [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function getCacheTtl(string $configKey, int $default = 3600): int
    {
        return config($configKey, $default);
    }
}

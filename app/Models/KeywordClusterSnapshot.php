<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordClusterSnapshot extends Model
{
    protected $fillable = [
        'cache_key',
        'keyword',
        'language_code',
        'location_code',
        'tree_json',
        'expires_at',
        'schema_version',
    ];

    protected function casts(): array
    {
        return [
            'tree_json' => 'array',
            'expires_at' => 'datetime',
            'schema_version' => 'integer',
            'location_code' => 'integer',
        ];
    }

    public function clusterJobs(): HasMany
    {
        return $this->hasMany(ClusterJob::class, 'snapshot_id');
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeForCacheKey(Builder $query, string $cacheKey): Builder
    {
        return $query->where('cache_key', $cacheKey);
    }
}

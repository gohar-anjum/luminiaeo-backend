<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ApiQuery extends Model
{
    use HasFactory;

    protected $table = 'api_queries';

    protected $fillable = [
        'api_provider',
        'feature',
        'query_hash',
        'query_parameters',
    ];

    protected function casts(): array
    {
        return [
            'query_parameters' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function results(): HasMany
    {
        return $this->hasMany(ApiResult::class, 'api_query_id');
    }

    public function latestResult(): HasOne
    {
        return $this->hasOne(ApiResult::class, 'api_query_id')->latestOfMany('fetched_at');
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeByHash(Builder $query, string $hash): Builder
    {
        return $query->where('query_hash', $hash);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('api_provider', $provider);
    }

    public function scopeForFeature(Builder $query, string $feature): Builder
    {
        return $query->where('feature', $feature);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function latestValidResult(): ?ApiResult
    {
        return $this->results()
            ->where('expires_at', '>', now())
            ->orderByDesc('fetched_at')
            ->first();
    }
}

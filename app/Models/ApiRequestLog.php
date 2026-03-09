<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiRequestLog extends Model
{
    use HasFactory;

    protected $table = 'api_request_logs';

    protected $fillable = [
        'user_id',
        'api_query_id',
        'api_result_id',
        'api_provider',
        'feature',
        'was_cache_hit',
        'credit_charged',
        'request_payload',
        'response_status',
        'response_time_ms',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'was_cache_hit' => 'boolean',
            'credit_charged' => 'boolean',
            'request_payload' => 'array',
            'response_status' => 'integer',
            'response_time_ms' => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiQuery(): BelongsTo
    {
        return $this->belongsTo(ApiQuery::class, 'api_query_id');
    }

    public function apiResult(): BelongsTo
    {
        return $this->belongsTo(ApiResult::class, 'api_result_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('api_provider', $provider);
    }

    public function scopeForFeature(Builder $query, string $feature): Builder
    {
        return $query->where('feature', $feature);
    }

    public function scopeErrors(Builder $query): Builder
    {
        return $query->whereNotNull('error_message');
    }

    public function scopeCacheHits(Builder $query): Builder
    {
        return $query->where('was_cache_hit', true);
    }

    public function scopeCacheMisses(Builder $query): Builder
    {
        return $query->where('was_cache_hit', false);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}

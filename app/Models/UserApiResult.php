<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserApiResult extends Model
{
    use HasFactory;

    protected $table = 'user_api_results';

    protected $fillable = [
        'user_id',
        'api_result_id',
        'feature_key',
        'was_cache_hit',
        'credit_charged',
        'credit_transaction_id',
        'accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'was_cache_hit' => 'boolean',
            'credit_charged' => 'boolean',
            'credit_transaction_id' => 'integer',
            'accessed_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function apiResult(): BelongsTo
    {
        return $this->belongsTo(ApiResult::class, 'api_result_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForFeature(Builder $query, string $featureKey): Builder
    {
        return $query->where('feature_key', $featureKey);
    }

    public function scopeCharged(Builder $query): Builder
    {
        return $query->where('credit_charged', true);
    }

    public function scopeCacheHits(Builder $query): Builder
    {
        return $query->where('was_cache_hit', true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiResult extends Model
{
    use HasFactory;

    protected $table = 'api_results';

    protected $fillable = [
        'api_query_id',
        'response_payload',
        'response_meta',
        'is_compressed',
        'byte_size',
        'fetched_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_meta' => 'array',
            'is_compressed' => 'boolean',
            'byte_size' => 'integer',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────

    public function apiQuery(): BelongsTo
    {
        return $this->belongsTo(ApiQuery::class, 'api_query_id');
    }

    public function userResults(): HasMany
    {
        return $this->hasMany(UserApiResult::class, 'api_result_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_api_results', 'api_result_id', 'user_id')
            ->withPivot(['feature_key', 'was_cache_hit', 'credit_charged', 'credit_transaction_id', 'accessed_at'])
            ->withTimestamps();
    }

    // ── Scopes ─────────────────────────────────────────────────────

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    // ── Payload accessors ──────────────────────────────────────────

    public function getPayload(): array
    {
        $raw = $this->response_payload;

        if ($this->is_compressed) {
            $raw = gzuncompress(base64_decode($raw));
        }

        return json_decode($raw, true) ?? [];
    }

    public static function compressPayload(string $json): string
    {
        return base64_encode(gzcompress($json, 6));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->isExpired();
    }
}

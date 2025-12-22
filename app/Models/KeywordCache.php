<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class KeywordCache extends Model
{
    use HasFactory;
    protected $fillable = [
        'keyword',
        'language_code',
        'location_code',
        'search_volume',
        'competition',
        'cpc',
        'difficulty',
        'serp_features',
        'related_keywords',
        'trends',
        'cluster_id',
        'cluster_data',
        'cached_at',
        'expires_at',
        'source',
        'metadata',
    ];

    protected $casts = [
        'search_volume' => 'integer',
        'competition' => 'float',
        'cpc' => 'float',
        'difficulty' => 'integer',
        'serp_features' => 'array',
        'related_keywords' => 'array',
        'trends' => 'array',
        'cluster_data' => 'array',
        'metadata' => 'array',
        'cached_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $dates = [
        'cached_at',
        'expires_at',
    ];

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeByKeyword(Builder $query, string $keyword, string $languageCode = 'en', int $locationCode = 2840): Builder
    {
        return $query->where('keyword', $keyword)
            ->where('language_code', $languageCode)
            ->where('location_code', $locationCode);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return !$this->isExpired();
    }
}

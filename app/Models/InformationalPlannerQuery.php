<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InformationalPlannerQuery extends Model
{
    protected $table = 'informational_planner_queries';

    protected $fillable = [
        'fingerprint',
        'seeds',
        'options',
        'keywords',
        'total_count',
        'expires_at',
    ];

    protected $casts = [
        'seeds' => 'array',
        'options' => 'array',
        'keywords' => 'array',
        'total_count' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'informational_planner_query_user')
            ->withTimestamps();
    }

    public function keywordRecords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'informational_planner_query_id');
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}

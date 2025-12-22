<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backlink extends Model
{
    protected $fillable = [
        'domain',
        'source_url',
        'anchor',
        'link_type',
        'source_domain',
        'domain_rank',
        'task_id',
        'ip',
        'asn',
        'hosting_provider',
        'whois_registrar',
        'domain_age_days',
        'content_fingerprint',
        'pbn_probability',
        'risk_level',
        'pbn_reasons',
        'pbn_signals',
        'safe_browsing_status',
        'safe_browsing_threats',
        'safe_browsing_checked_at',
        'backlink_spam_score',
    ];

    protected $casts = [
        'domain_rank' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'pbn_probability' => 'float',
        'domain_age_days' => 'integer',
        'pbn_reasons' => 'array',
        'pbn_signals' => 'array',
        'safe_browsing_threats' => 'array',
        'safe_browsing_checked_at' => 'datetime',
    ];

    public function seoTask(): BelongsTo
    {
        return $this->belongsTo(SeoTask::class, 'task_id', 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'domain', 'domain');
    }

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function scopeForTask($query, string $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('link_type', $type);
    }

    public function scopeMinDomainRank($query, float $minRank)
    {
        return $query->where('domain_rank', '>=', $minRank);
    }
}

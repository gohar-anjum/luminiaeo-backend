<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordResearchJob extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'query',
        'status',
        'result',
    ];

    // Note: project_id, language_code, geoTargetId, settings, progress, error_message,
    // started_at, and completed_at are conditionally handled in KeywordService::createKeywordResearch()
    // - only included if columns exist in database

    protected $casts = [
        'result' => 'array',
        'settings' => 'array',
        'progress' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function keywords(): HasMany
    {
        // Check if the foreign key column exists before defining the relationship
        try {
            $columns = \Illuminate\Support\Facades\Schema::getColumnListing('keywords');
            if (!in_array('keyword_research_job_id', $columns)) {
                // Return a relationship that won't execute queries
                return $this->hasMany(Keyword::class, 'id', 'id')->whereRaw('1 = 0');
            }
        } catch (\Exception $e) {
            // If we can't check, return a safe relationship
            return $this->hasMany(Keyword::class, 'id', 'id')->whereRaw('1 = 0');
        }
        
        return $this->hasMany(Keyword::class);
    }

    public function clusters(): HasMany
    {
        // Check if the foreign key column exists before defining the relationship
        try {
            $columns = \Illuminate\Support\Facades\Schema::getColumnListing('keyword_clusters');
            if (!in_array('keyword_research_job_id', $columns)) {
                // Return a relationship that won't execute queries
                return $this->hasMany(KeywordCluster::class, 'id', 'id')->whereRaw('1 = 0');
            }
        } catch (\Exception $e) {
            // If we can't check, return a safe relationship
            return $this->hasMany(KeywordCluster::class, 'id', 'id')->whereRaw('1 = 0');
        }
        
        return $this->hasMany(KeywordCluster::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}

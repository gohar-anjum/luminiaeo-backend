<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTask extends Model
{
    protected $fillable = [
        'task_id',
        'type',
        'domain',
        'status',
        'payload',
        'result',
        'error_message',
        'retry_count',
        'submitted_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'retry_count' => 'integer',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Type constants
    public const TYPE_BACKLINKS = 'backlinks';
    public const TYPE_SEARCH_VOLUME = 'search_volume';
    public const TYPE_KEYWORDS = 'keywords';

    /**
     * Get the backlinks for this task
     */
    public function backlinks(): HasMany
    {
        return $this->hasMany(Backlink::class, 'task_id', 'task_id');
    }

    /**
     * Get the project that this task belongs to (via domain)
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'domain', 'domain');
    }

    /**
     * Mark task as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'submitted_at' => $this->submitted_at ?? now(),
        ]);
    }

    /**
     * Mark task as completed
     */
    public function markAsCompleted(array $result = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark task as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'failed_at' => now(),
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Increment retry count
     */
    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }

    /**
     * Check if task is pending
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if task is processing
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if task is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if task is failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Scope a query to filter by status
     */
    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to filter by domain
     */
    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    /**
     * Scope a query to get pending tasks
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to get failed tasks
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}


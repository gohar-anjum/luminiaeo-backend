<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTask extends Model
{
    use HasFactory;
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

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const TYPE_BACKLINKS = 'backlinks';
    public const TYPE_SEARCH_VOLUME = 'search_volume';
    public const TYPE_KEYWORDS = 'keywords';

    public function backlinks(): HasMany
    {
        return $this->hasMany(Backlink::class, 'task_id', 'task_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'domain', 'domain');
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'submitted_at' => $this->submitted_at ?? now(),
        ]);
    }

    public function markAsCompleted(array $result = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'failed_at' => now(),
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForDomain($query, string $domain)
    {
        return $query->where('domain', $domain);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}

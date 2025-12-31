<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FaqTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'task_id',
        'url',
        'topic',
        'alsoasked_search_id',
        'serp_questions',
        'alsoasked_questions',
        'question_keywords',
        'options',
        'status',
        'error_message',
        'faq_id',
        'completed_at',
    ];

    protected $casts = [
        'serp_questions' => 'array',
        'alsoasked_questions' => 'array',
        'question_keywords' => 'array',
        'options' => 'array',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($task) {
            if (empty($task->task_id)) {
                $task->task_id = 'faq_' . Str::random(32);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function faq(): BelongsTo
    {
        return $this->belongsTo(Faq::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(int $faqId): void
    {
        $this->update([
            'status' => 'completed',
            'faq_id' => $faqId,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}

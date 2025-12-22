<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Faq extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'url',
        'topic',
        'faqs',
        'options',
        'source_hash',
        'api_calls_saved',
    ];

    protected $casts = [
        'faqs' => 'array',
        'options' => 'array',
        'api_calls_saved' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function incrementApiCallsSaved(): void
    {
        $this->increment('api_calls_saved');
    }

    public function scopeForUrl($query, string $url)
    {
        return $query->where('url', $url);
    }

    public function scopeForTopic($query, string $topic)
    {
        return $query->where('topic', $topic);
    }

    public function scopeByHash($query, string $hash)
    {
        return $query->where('source_hash', $hash);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'url',
        'original_title',
        'original_description',
        'suggested_title',
        'suggested_description',
        'keywords',
        'intent',
        'word_count',
        'analyzed_at',
    ];

    protected $casts = [
        'keywords' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

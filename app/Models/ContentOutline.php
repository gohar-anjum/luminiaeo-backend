<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentOutline extends Model
{
    protected $fillable = [
        'user_id',
        'keyword',
        'tone',
        'outline',
        'semantic_keywords',
        'intent',
        'generated_at',
    ];

    protected $casts = [
        'outline' => 'array',
        'semantic_keywords' => 'array',
        'generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

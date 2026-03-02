<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemanticAnalysis extends Model
{
    protected $fillable = [
        'user_id',
        'source_url',
        'comparison_type',
        'comparison_value',
        'semantic_score',
        'analyzed_at',
    ];

    protected $casts = [
        'semantic_score' => 'float',
        'analyzed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

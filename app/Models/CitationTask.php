<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CitationTask extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'url',
        'status',
        'queries',
        'results',
        'competitors',
        'meta',
    ];

    protected $casts = [
        'queries' => 'array',
        'results' => 'array',
        'competitors' => 'array',
        'meta' => 'array',
    ];

    public function markStatus(string $status): self
    {
        $this->status = $status;
        $this->save();

        return $this;
    }
}

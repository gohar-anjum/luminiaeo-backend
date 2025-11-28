<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CitationTask extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
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


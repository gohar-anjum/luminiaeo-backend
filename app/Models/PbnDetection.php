<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PbnDetection extends Model
{
    protected $fillable = [
        'task_id',
        'domain',
        'status',
        'high_risk_count',
        'medium_risk_count',
        'low_risk_count',
        'latency_ms',
        'analysis_started_at',
        'analysis_completed_at',
        'status_message',
        'summary',
        'response_payload',
    ];

    protected $casts = [
        'summary' => 'array',
        'response_payload' => 'array',
        'analysis_started_at' => 'datetime',
        'analysis_completed_at' => 'datetime',
    ];
}


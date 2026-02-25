<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class StripeEvent extends Model
{
    protected $table = 'stripe_events';

    protected $fillable = [
        'stripe_event_id',
        'type',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}

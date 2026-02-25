<?php

namespace App\Domain\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $table = 'features';

    protected $fillable = [
        'key',
        'name',
        'credit_cost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_cost' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

<?php

namespace App\Domain\Billing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_USAGE = 'usage';

    public const TYPE_REFUND = 'refund';

    public const TYPE_BONUS = 'bonus';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $table = 'credit_transactions';

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'feature_key',
        'reference_type',
        'reference_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

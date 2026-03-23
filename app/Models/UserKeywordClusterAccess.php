<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserKeywordClusterAccess extends Model
{
    protected $table = 'user_keyword_cluster_access';

    protected $fillable = [
        'user_id',
        'cache_key',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

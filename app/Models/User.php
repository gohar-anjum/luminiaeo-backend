<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable ,HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'credits_balance',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credits_balance' => 'integer',
        ];
    }

    public function creditTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Domain\Billing\Models\CreditTransaction::class);
    }

    public function apiResults(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(ApiResult::class, 'user_api_results', 'user_id', 'api_result_id')
            ->withPivot(['feature_key', 'was_cache_hit', 'credit_charged', 'credit_transaction_id', 'accessed_at'])
            ->withTimestamps();
    }

    public function userApiResults(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserApiResult::class);
    }

    public function apiRequestLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }
}

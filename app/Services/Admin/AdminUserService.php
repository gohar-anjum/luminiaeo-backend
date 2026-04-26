<?php

namespace App\Services\Admin;

use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Domain\Billing\Models\CreditTransaction;
use App\Models\User;
use App\Support\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Admin user listing, suspension, and credit adjustments.
 */
class AdminUserService
{
    public function __construct(
        protected WalletServiceInterface $walletService
    ) {}

    /**
     * Customer (non-admin) users only — admin accounts are excluded from this listing.
     *
     * @param  array{search?: string|null, suspended?: bool|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $q = User::query()
            ->where(function ($w) {
                $w->where('is_admin', false)->orWhereNull('is_admin');
            })
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $q->where(function ($w) use ($term) {
                $w->where('email', 'like', $term)
                    ->orWhere('name', 'like', $term);
            });
        }

        if ($filters['suspended'] === true) {
            $q->whereNotNull('suspended_at');
        } elseif ($filters['suspended'] === false) {
            $q->whereNull('suspended_at');
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'suspended_at' => Iso8601::utcZ($user->suspended_at),
            'credits_balance' => (int) $user->credits_balance,
            'email_verified_at' => Iso8601::utcZ($user->email_verified_at),
            'created_at' => Iso8601::utcZ($user->created_at),
        ];
    }

    public function createCustomerUser(string $name, string $email, string $password): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);
        $signupBonus = (int) config('billing.signup_bonus_credits', 10);
        if ($signupBonus > 0) {
            $this->walletService->addCredits($user, $signupBonus, 'bonus', [
                'metadata' => ['reason' => 'signup_bonus', 'source' => 'admin_created'],
            ]);
        }

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCreditTransaction(CreditTransaction $tx): array
    {
        return [
            'id' => $tx->id,
            'type' => $tx->type,
            'amount' => (int) $tx->amount,
            'balance_after' => (int) $tx->balance_after,
            'user_id' => (int) $tx->user_id,
            'created_at' => Iso8601::utcZ($tx->created_at),
        ];
    }

    public function suspend(User $user): void
    {
        $user->forceFill(['suspended_at' => now()])->save();
    }

    public function unsuspend(User $user): void
    {
        $user->forceFill(['suspended_at' => null])->save();
    }

    /**
     * Positive delta adds credits; negative removes (when balance allows).
     */
    public function adjustCredits(User $user, int $delta, ?int $adminUserId = null, ?string $note = null): CreditTransaction
    {
        if ($delta === 0) {
            throw new \InvalidArgumentException('Delta must be non-zero.');
        }

        $context = [
            'metadata' => array_filter([
                'admin_user_id' => $adminUserId,
                'source' => 'admin_adjustment',
                'note' => $note,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        if ($delta > 0) {
            return $this->walletService->addCredits($user, $delta, CreditTransaction::TYPE_ADJUSTMENT, $context);
        }

        return $this->walletService->deductCredits($user, abs($delta), CreditTransaction::TYPE_ADJUSTMENT, $context);
    }
}

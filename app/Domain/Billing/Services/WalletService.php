<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Domain\Billing\Events\CreditsAdded;
use App\Domain\Billing\Events\CreditsDeducted;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Domain\Billing\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WalletService implements WalletServiceInterface
{
    public function addCredits(User $user, int $amount, string $type, array $context = []): CreditTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive for credit addition.');
        }

        return DB::transaction(function () use ($user, $amount, $type, $context) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $newBalance = $user->credits_balance + $amount;

            $tx = CreditTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'feature_key' => $context['feature_key'] ?? null,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ]);

            $user->update(['credits_balance' => $newBalance]);

            CreditsAdded::dispatch($user, $amount, $type, $context);

            return $tx;
        });
    }

    public function deductCredits(User $user, int $amount, string $type, array $context = []): CreditTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive for deduction (stored as negative in ledger).');
        }

        return DB::transaction(function () use ($user, $amount, $type, $context) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $this->assertSufficientCredits($user, $amount);

            $deduction = -$amount;
            $newBalance = $user->credits_balance + $deduction;

            $tx = CreditTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $deduction,
                'balance_after' => $newBalance,
                'feature_key' => $context['feature_key'] ?? null,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ]);

            $user->update(['credits_balance' => $newBalance]);

            CreditsDeducted::dispatch($user, $amount, $type, $context);

            return $tx;
        });
    }

    public function getBalance(User $user): int
    {
        return (int) $user->credits_balance;
    }

    public function assertSufficientCredits(User $user, int $required): void
    {
        $balance = $this->getBalance($user);
        if ($balance < $required) {
            throw new InsufficientCreditsException(
                "Insufficient credits. Required: {$required}, available: {$balance}."
            );
        }
    }
}

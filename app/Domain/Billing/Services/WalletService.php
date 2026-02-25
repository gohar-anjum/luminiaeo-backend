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

    public function reserveCredits(User $user, string $featureKey, int $amount, array $context = []): CreditTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive for reservation.');
        }

        return DB::transaction(function () use ($user, $featureKey, $amount, $context) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $this->assertSufficientCredits($user, $amount);

            $deduction = -$amount;
            $newBalance = $user->credits_balance + $deduction;

            $tx = CreditTransaction::create([
                'user_id' => $user->id,
                'type' => CreditTransaction::TYPE_USAGE,
                'amount' => $deduction,
                'balance_after' => $newBalance,
                'feature_key' => $featureKey,
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'metadata' => $context['metadata'] ?? null,
                'status' => CreditTransaction::STATUS_PENDING,
                'idempotency_key' => $context['idempotency_key'] ?? null,
            ]);

            $user->update(['credits_balance' => $newBalance]);

            return $tx;
        });
    }

    public function completeReservation(int $creditTransactionId): void
    {
        DB::transaction(function () use ($creditTransactionId) {
            $tx = CreditTransaction::where('id', $creditTransactionId)
                ->where('status', CreditTransaction::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($tx) {
                $tx->update(['status' => CreditTransaction::STATUS_COMPLETED]);
            }
        });
    }

    public function reverseReservation(int $creditTransactionId): void
    {
        DB::transaction(function () use ($creditTransactionId) {
            $tx = CreditTransaction::where('id', $creditTransactionId)
                ->where('status', CreditTransaction::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if (! $tx) {
                return;
            }

            $user = User::where('id', $tx->user_id)->lockForUpdate()->firstOrFail();
            $refundAmount = (int) abs($tx->amount);
            $newBalance = $user->credits_balance + $refundAmount;

            $tx->update(['status' => CreditTransaction::STATUS_REVERSED]);

            CreditTransaction::create([
                'user_id' => $user->id,
                'type' => CreditTransaction::TYPE_REFUND,
                'amount' => $refundAmount,
                'balance_after' => $newBalance,
                'feature_key' => $tx->feature_key,
                'reference_type' => 'reversal_of',
                'reference_id' => (string) $tx->id,
                'metadata' => array_merge($tx->metadata ?? [], ['original_transaction_id' => $tx->id]),
                'status' => CreditTransaction::STATUS_COMPLETED,
                'idempotency_key' => null,
            ]);

            $user->update(['credits_balance' => $newBalance]);
        });
    }
}

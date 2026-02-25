<?php

namespace App\Domain\Billing\Contracts;

use App\Models\User;

interface WalletServiceInterface
{
    public function addCredits(
        User $user,
        int $amount,
        string $type,
        array $context = []
    ): \App\Domain\Billing\Models\CreditTransaction;

    public function deductCredits(
        User $user,
        int $amount,
        string $type,
        array $context = []
    ): \App\Domain\Billing\Models\CreditTransaction;

    public function getBalance(User $user): int;

    public function assertSufficientCredits(User $user, int $required): void;

    /**
     * Reserve credits at request time (balance decreased, transaction status pending).
     * Call completeReservation on success or reverseReservation on failure.
     */
    public function reserveCredits(
        User $user,
        string $featureKey,
        int $amount,
        array $context = []
    ): \App\Domain\Billing\Models\CreditTransaction;

    /**
     * Mark a reservation as completed (idempotent).
     */
    public function completeReservation(int $creditTransactionId): void;

    /**
     * Reverse a reservation (refund balance, mark reversed). Idempotent.
     */
    public function reverseReservation(int $creditTransactionId): void;
}

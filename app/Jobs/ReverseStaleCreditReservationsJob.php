<?php

namespace App\Jobs;

use App\Domain\Billing\Models\CreditTransaction;
use App\Domain\Billing\Contracts\WalletServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReverseStaleCreditReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Pending reservations older than this (hours) are reversed. */
    public static int $staleHours = 48;

    public function handle(WalletServiceInterface $walletService): void
    {
        $cutoff = now()->subHours(static::$staleHours);
        $stale = CreditTransaction::where('type', CreditTransaction::TYPE_USAGE)
            ->where('status', CreditTransaction::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $tx) {
            try {
                $walletService->reverseReservation($tx->id);
                Log::info('Reversed stale credit reservation.', [
                    'credit_transaction_id' => $tx->id,
                    'user_id' => $tx->user_id,
                    'created_at' => $tx->created_at->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to reverse stale reservation.', [
                    'credit_transaction_id' => $tx->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

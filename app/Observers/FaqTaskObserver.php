<?php

namespace App\Observers;

use App\Models\FaqTask;
use App\Domain\Billing\Contracts\WalletServiceInterface;

class FaqTaskObserver
{
    public function updated(FaqTask $task): void
    {
        if ($task->wasChanged('status') && $task->status === 'failed' && $task->credit_reservation_id) {
            app(WalletServiceInterface::class)->reverseReservation($task->credit_reservation_id);
        }
    }
}

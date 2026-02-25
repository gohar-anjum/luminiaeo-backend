<?php

namespace App\Observers;

use App\Models\CitationTask;
use App\Domain\Billing\Contracts\WalletServiceInterface;

class CitationTaskObserver
{
    public function updated(CitationTask $task): void
    {
        if ($task->wasChanged('status') && $task->status === CitationTask::STATUS_FAILED && $task->credit_reservation_id) {
            app(WalletServiceInterface::class)->reverseReservation($task->credit_reservation_id);
        }
    }
}

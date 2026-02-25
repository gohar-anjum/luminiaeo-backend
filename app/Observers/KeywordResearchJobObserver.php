<?php

namespace App\Observers;

use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Models\KeywordResearchJob;

class KeywordResearchJobObserver
{
    public function updated(KeywordResearchJob $job): void
    {
        if ($job->wasChanged('status') && $job->status === KeywordResearchJob::STATUS_FAILED && $job->credit_reservation_id) {
            app(WalletServiceInterface::class)->reverseReservation($job->credit_reservation_id);
        }
    }
}

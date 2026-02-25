<?php

namespace App\Domain\Billing\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreditsDeducted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public int $amount,
        public string $type,
        public array $context = []
    ) {}
}

<?php

namespace App\Traits;

use Carbon\Carbon;

trait HasTimestamps
{
    public function wasCreatedRecently(int $minutes = 5): bool
    {
        return $this->created_at && $this->created_at->isAfter(now()->subMinutes($minutes));
    }

    public function wasUpdatedRecently(int $minutes = 5): bool
    {
        return $this->updated_at && $this->updated_at->isAfter(now()->subMinutes($minutes));
    }

    public function getTimeSinceCreation(): string
    {
        return $this->created_at ? $this->created_at->diffForHumans() : 'N/A';
    }

    public function getTimeSinceUpdate(): string
    {
        return $this->updated_at ? $this->updated_at->diffForHumans() : 'N/A';
    }

    public function isOlderThan(int $days): bool
    {
        return $this->created_at && $this->created_at->isBefore(now()->subDays($days));
    }
}

<?php

namespace App\Services\Admin;

use App\Support\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Subscription;

/**
 * Stripe subscriptions (Cashier).
 */
class AdminSubscriptionService
{
    /**
     * @return LengthAwarePaginator<int, Subscription>
     */
    public function paginate(int $perPage = 30): LengthAwarePaginator
    {
        if (! Schema::hasTable('subscriptions')) {
            return new ConcretePaginator([], 0, $perPage, 1);
        }

        return Subscription::query()
            ->with('items')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSubscription(Subscription $sub): array
    {
        $plan = $sub->stripe_price
            ?? $sub->items->first()?->stripe_price
            ?? $sub->type;

        return [
            'id' => $sub->id,
            'user_id' => $sub->user_id,
            'plan' => (string) $plan,
            'status' => $sub->stripe_status,
            'current_period_end' => Iso8601::utcZ($sub->ends_at),
        ];
    }
}

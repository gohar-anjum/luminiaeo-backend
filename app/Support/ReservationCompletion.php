<?php

namespace App\Support;

use App\Domain\Billing\Contracts\WalletServiceInterface;
use Illuminate\Http\Request;

/**
 * Helper for sync billable endpoints: complete reservation on success, reverse on exception.
 */
final class ReservationCompletion
{
    public static function run(Request $request, callable $action): mixed
    {
        return self::runWithCondition($request, $action, fn () => true);
    }

    /**
     * Run action and conditionally complete or reverse reservation.
     * When $shouldComplete($result) is false, reverse instead of complete (e.g. when returning cached).
     */
    public static function runWithCondition(Request $request, callable $action, callable $shouldComplete): mixed
    {
        $reservation = $request->attributes->get('credit_reservation');
        $reservationId = $reservation['transaction_id'] ?? null;

        try {
            $result = $action();
            if ($reservationId) {
                if ($shouldComplete($result)) {
                    app(WalletServiceInterface::class)->completeReservation($reservationId);
                } else {
                    app(WalletServiceInterface::class)->reverseReservation($reservationId);
                }
            }
            return $result;
        } catch (\Throwable $e) {
            if ($reservationId) {
                app(WalletServiceInterface::class)->reverseReservation($reservationId);
            }
            throw $e;
        }
    }
}

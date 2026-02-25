<?php

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Contracts\WebhookServiceInterface;
use App\Domain\Billing\Models\CreditTransaction;
use App\Domain\Billing\Models\StripeEvent;
use App\Domain\Billing\Services\WalletService;
use App\Jobs\ProcessStripeWebhookJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;

class WebhookService implements WebhookServiceInterface
{
    public function __construct(
        protected string $webhookSecret,
        protected WalletService $walletService
    ) {}

    public function handleWebhook(string $payload, string $signature): void
    {
        if ($this->webhookSecret === '') {
            throw new \RuntimeException('Stripe webhook secret is not configured (STRIPE_WEBHOOK_SECRET).');
        }

        $event = $this->constructEvent($payload, $signature);

        $stripeEvent = StripeEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            [
                'type' => $event->type,
                'processed_at' => now(),
            ]
        );

        if (! $stripeEvent->wasRecentlyCreated) {
            Log::info('Stripe webhook idempotency: duplicate event ignored.', ['event_id' => $event->id]);
            return;
        }

        $dataArray = json_decode(json_encode($event->data->object), true);
        if (! is_array($dataArray)) {
            Log::warning('Stripe webhook: could not encode event data.', ['event_id' => $event->id]);
            return;
        }

        ProcessStripeWebhookJob::dispatch($event->id, $event->type, $dataArray);
    }

    public function processEventPayload(string $eventId, string $eventType, array $dataObject): void
    {
        match ($eventType) {
            'checkout.session.completed' => $this->handleCheckoutCompletedFromArray($dataObject),
            'charge.refunded' => $this->handleRefundFromArray($dataObject),
            default => Log::info('Stripe webhook unhandled type.', ['event_id' => $eventId, 'type' => $eventType]),
        };
    }

    protected function constructEvent(string $payload, string $signature): \Stripe\Event
    {
        try {
            return Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            Log::warning('Stripe webhook invalid payload.', ['error' => $e->getMessage()]);
            throw $e;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    protected function handleCheckoutCompletedFromArray(array $session): void
    {
        $paymentStatus = $session['payment_status'] ?? null;
        if ($paymentStatus !== null && $paymentStatus !== 'paid') {
            return;
        }

        $metadata = $session['metadata'] ?? [];
        $userId = $metadata['user_id'] ?? $session['client_reference_id'] ?? null;
        $credits = (int) ($metadata['credits'] ?? 0);

        if (! $userId || $credits <= 0) {
            Log::warning('Stripe checkout.session.completed missing user_id or credits.', [
                'session_id' => $session['id'] ?? null,
            ]);
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            Log::warning('Stripe checkout: user not found.', ['user_id' => $userId]);
            return;
        }

        $sessionId = $session['id'] ?? null;
        if ($sessionId && CreditTransaction::where('reference_type', 'stripe_checkout_session')
            ->where('reference_id', $sessionId)
            ->where('type', 'purchase')
            ->exists()) {
            Log::info('Stripe checkout.session.completed: session already credited (e.g. via confirm-session).', ['session_id' => $sessionId]);
            return;
        }

        $paymentIntentId = $session['payment_intent'] ?? null;

        $metadataStored = ['session_id' => $sessionId];
        if ($paymentIntentId !== null) {
            $metadataStored['payment_intent_id'] = $paymentIntentId;
        }

        $this->walletService->addCredits($user, $credits, 'purchase', [
            'reference_type' => 'stripe_checkout_session',
            'reference_id' => $sessionId,
            'metadata' => $metadataStored,
        ]);
    }

    protected function handleRefundFromArray(array $charge): void
    {
        $paymentIntentId = $charge['payment_intent'] ?? null;
        if (! $paymentIntentId) {
            Log::info('Stripe charge.refunded: no payment_intent on charge.', ['charge_id' => $charge['id'] ?? null]);
            return;
        }

        $amountRefunded = (int) ($charge['amount_refunded'] ?? 0);
        $amountTotal = (int) ($charge['amount'] ?? 0);
        if ($amountTotal <= 0) {
            return;
        }

        $purchase = CreditTransaction::where('type', CreditTransaction::TYPE_PURCHASE)
            ->where('reference_type', 'stripe_checkout_session')
            ->where('metadata->payment_intent_id', $paymentIntentId)
            ->first();

        if (! $purchase) {
            Log::warning('Stripe charge.refunded: no purchase found for payment_intent.', [
                'payment_intent_id' => $paymentIntentId,
                'charge_id' => $charge['id'] ?? null,
            ]);
            return;
        }

        $creditsAdded = (int) $purchase->amount;
        if ($creditsAdded <= 0) {
            return;
        }

        $user = $purchase->user;
        if (! $user) {
            Log::warning('Stripe charge.refunded: purchase user not found.', ['transaction_id' => $purchase->id]);
            return;
        }

        if ($amountRefunded >= $amountTotal) {
            $creditsToDeduct = $creditsAdded;
        } else {
            $creditsToDeduct = (int) round($creditsAdded * ($amountRefunded / $amountTotal));
            if ($creditsToDeduct <= 0) {
                return;
            }
        }

        try {
            $this->walletService->deductCredits($user, $creditsToDeduct, CreditTransaction::TYPE_REFUND, [
                'reference_type' => 'stripe_charge',
                'reference_id' => $charge['id'] ?? null,
                'metadata' => [
                    'charge_id' => $charge['id'] ?? null,
                    'payment_intent_id' => $paymentIntentId,
                    'original_purchase_transaction_id' => $purchase->id,
                ],
            ]);
            Log::info('Stripe charge.refunded: credits deducted.', [
                'user_id' => $user->id,
                'credits_deducted' => $creditsToDeduct,
                'charge_id' => $charge['id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe charge.refunded: failed to deduct credits.', [
                'user_id' => $user->id,
                'credits_to_deduct' => $creditsToDeduct,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

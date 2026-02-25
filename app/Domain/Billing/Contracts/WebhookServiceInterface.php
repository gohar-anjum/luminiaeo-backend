<?php

namespace App\Domain\Billing\Contracts;

interface WebhookServiceInterface
{
    /**
     * Verify payload signature and process the Stripe event.
     * Idempotent: duplicate event IDs are skipped. Dispatches job and returns quickly.
     *
     * @param  string  $payload  Raw request body
     * @param  string  $signature  Stripe-Signature header
     */
    public function handleWebhook(string $payload, string $signature): void;

    /**
     * Process a Stripe event from job (event data as array). Used by ProcessStripeWebhookJob.
     *
     * @param  string  $eventId  Stripe event id
     * @param  string  $eventType  e.g. checkout.session.completed, charge.refunded
     * @param  array  $dataObject  event.data.object as array
     */
    public function processEventPayload(string $eventId, string $eventType, array $dataObject): void;
}

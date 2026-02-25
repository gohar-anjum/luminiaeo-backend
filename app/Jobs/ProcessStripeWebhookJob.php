<?php

namespace App\Jobs;

use App\Domain\Billing\Contracts\WebhookServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessStripeWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $eventId,
        public string $eventType,
        public array $dataObject
    ) {}

    public function handle(WebhookServiceInterface $webhookService): void
    {
        $webhookService->processEventPayload($this->eventId, $this->eventType, $this->dataObject);
    }
}

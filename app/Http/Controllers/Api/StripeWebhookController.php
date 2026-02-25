<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\Contracts\WebhookServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StripeWebhookController extends Controller
{
    public function __construct(
        protected WebhookServiceInterface $webhookService
    ) {}

    /**
     * Stripe webhook endpoint. No auth; verification is via Stripe-Signature.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        if (empty($signature)) {
            return response('Missing Stripe-Signature', 400);
        }

        try {
            $this->webhookService->handleWebhook($payload, $signature);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'webhook secret')) {
                return response('Webhook not configured', 503);
            }
            report($e);
            return response('Webhook processing failed', 500);
        } catch (\UnexpectedValueException $e) {
            return response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return response('Invalid signature', 400);
        } catch (\Throwable $e) {
            report($e);
            return response('Webhook processing failed', 500);
        }

        return response('', 200);
    }
}

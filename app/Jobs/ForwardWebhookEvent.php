<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\WebhookRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Delivers one verified webhook to the project it belongs to. The gateway's
 * only responsibility ends at a successful handoff — everything past that
 * (crediting a fee payment, recording a POS sale, whatever the event means)
 * is the receiving project's own logic on its own internal endpoint.
 */
class ForwardWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public WebhookEvent $webhookEvent)
    {
        //
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(WebhookRouter $router): void
    {
        $resolved = $router->resolve($this->webhookEvent->reference);

        if ($resolved === null || blank($resolved['forward_url'])) {
            $this->webhookEvent->update([
                'status' => WebhookEvent::STATUS_UNMATCHED,
                'last_error' => 'No forward_url configured for this project prefix.',
            ]);

            return;
        }

        // Relayed byte-for-byte, not re-encoded from the decoded payload —
        // the receiving project re-checks Paystack's own signature against
        // this body, which only matches the original bytes Paystack signed.
        $rawBody = $this->webhookEvent->raw_body;
        $gatewaySignature = hash_hmac('sha256', $rawBody, (string) $resolved['forward_secret']);

        $response = Http::withBody($rawBody, 'application/json')
            ->withHeaders([
                'x-paystack-signature' => $this->webhookEvent->paystack_signature,
                'X-Gateway-Signature' => $gatewaySignature,
                'X-Gateway-Event' => $this->webhookEvent->event,
                'X-Gateway-Reference' => $this->webhookEvent->reference,
            ])
            ->timeout(15)
            ->post($resolved['forward_url']);

        $this->webhookEvent->increment('attempts');

        if ($response->successful()) {
            $this->webhookEvent->update([
                'status' => WebhookEvent::STATUS_FORWARDED,
                'forwarded_at' => now(),
                'last_error' => null,
            ]);

            return;
        }

        $this->webhookEvent->update([
            'last_error' => "Forward failed with status {$response->status()}: ".$response->body(),
        ]);

        throw new RuntimeException("Forwarding webhook {$this->webhookEvent->id} to {$resolved['name']} failed with status {$response->status()}.");
    }

    public function failed(?Throwable $exception): void
    {
        $this->webhookEvent->update([
            'status' => WebhookEvent::STATUS_FAILED,
            'last_error' => $exception?->getMessage(),
        ]);
    }
}

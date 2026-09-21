<?php

namespace App\Http\Controllers;

use App\Jobs\ForwardWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\PaystackSignatureVerifier;
use App\Services\WebhookRouter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The one webhook URL Paystack is configured with, shared by every project
 * on this account. Verifies the payload, works out which project it
 * belongs to from its reference prefix, and queues delivery to that
 * project — the actual charge/transfer handling stays in each project's
 * own codebase, this only routes.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaystackSignatureVerifier $verifier,
        WebhookRouter $router,
    ): Response {
        $rawBody = $request->getContent();
        $signatureValid = $verifier->verify($rawBody, $request->header('x-paystack-signature'));

        $payload = (array) $request->json()->all();
        $event = (string) ($payload['event'] ?? 'unknown');
        $data = (array) ($payload['data'] ?? []);
        $reference = isset($data['reference']) ? (string) $data['reference'] : null;
        $paystackId = isset($data['id']) ? (string) $data['id'] : $reference;

        // Paystack retries a webhook until it gets a 200, so the same event
        // can arrive more than once — this is what makes a retry a no-op
        // instead of a duplicate delivery downstream.
        $webhookEvent = WebhookEvent::query()->firstOrNew([
            'event' => $event,
            'paystack_id' => $paystackId,
        ]);

        if ($webhookEvent->exists) {
            return response()->noContent();
        }

        $resolved = $signatureValid ? $router->resolve($reference) : null;

        $webhookEvent->forceFill([
            'provider' => 'paystack',
            'reference' => $reference,
            'signature_valid' => $signatureValid,
            'paystack_signature' => $request->header('x-paystack-signature'),
            'raw_body' => $rawBody,
            'payload' => $payload,
            'prefix' => $resolved['prefix'] ?? null,
            'project' => $resolved['name'] ?? null,
            'status' => match (true) {
                ! $signatureValid => WebhookEvent::STATUS_FAILED,
                $resolved === null => WebhookEvent::STATUS_UNMATCHED,
                default => WebhookEvent::STATUS_PENDING,
            },
            'last_error' => $signatureValid ? null : 'Signature verification failed.',
        ])->save();

        if ($signatureValid && $resolved !== null) {
            ForwardWebhookEvent::dispatch($webhookEvent);
        }

        // Paystack only cares that this returns 200 quickly; the actual
        // delivery to the owning project happens on the queue.
        return response()->noContent();
    }
}

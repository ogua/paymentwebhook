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
        $mode = $verifier->mode($rawBody, $request->header('x-paystack-signature'));
        $signatureValid = $mode !== null;

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

        // A test-mode event is only delivered to projects that opted into the
        // test lane; for any other project it is kept here and goes nowhere.
        $testLaneClosed = $mode === PaystackSignatureVerifier::MODE_TEST
            && $resolved !== null
            && ! in_array($resolved['prefix'], (array) config('paystack-gateway.test_prefixes', []), true);

        $webhookEvent->forceFill([
            'provider' => 'paystack',
            'reference' => $reference,
            'signature_valid' => $signatureValid,
            'mode' => $mode,
            'paystack_signature' => $request->header('x-paystack-signature'),
            'raw_body' => $rawBody,
            'payload' => $payload,
            'prefix' => $resolved['prefix'] ?? null,
            'project' => $resolved['name'] ?? null,
            'status' => match (true) {
                ! $signatureValid => WebhookEvent::STATUS_FAILED,
                $resolved === null, $testLaneClosed => WebhookEvent::STATUS_UNMATCHED,
                default => WebhookEvent::STATUS_PENDING,
            },
            'last_error' => match (true) {
                ! $signatureValid => 'Signature verification failed.',
                $testLaneClosed => 'Test-mode event for a project not in PAYSTACK_TEST_PREFIXES.',
                default => null,
            },
        ])->save();

        if ($signatureValid && $resolved !== null && ! $testLaneClosed) {
            ForwardWebhookEvent::dispatch($webhookEvent);
        }

        // Paystack only cares that this returns 200 quickly; the actual
        // delivery to the owning project happens on the queue.
        return response()->noContent();
    }
}

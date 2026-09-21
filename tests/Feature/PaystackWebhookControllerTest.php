<?php

use App\Jobs\ForwardWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

function paystackPayload(string $reference, string $event = 'charge.success'): array
{
    return [
        'event' => $event,
        'data' => [
            'id' => 12345,
            'reference' => $reference,
            'amount' => 50000,
            'status' => 'success',
        ],
    ];
}

function signedPaystackRequest(array $payload, ?string $secret = 'whsec_test'): array
{
    $body = json_encode($payload);
    $signature = $secret ? hash_hmac('sha512', $body, $secret) : 'not-a-real-signature';

    return [$body, $signature];
}

beforeEach(function () {
    config([
        'paystack-gateway.paystack_secret_key' => 'whsec_test',
        'paystack-gateway.default_forward_secret' => 'forward_secret',
        'paystack-gateway.projects' => [
            'TEST' => [
                'name' => 'Test Project',
                'forward_url' => 'https://test-project.example/internal/paystack-webhook',
                'forward_secret' => null,
            ],
        ],
    ]);
});

it('accepts a correctly signed webhook and queues it for forwarding', function () {
    Queue::fake();

    $payload = paystackPayload('TEST-abc123');
    [$body, $signature] = signedPaystackRequest($payload);

    $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertNoContent();

    $event = WebhookEvent::query()->sole();

    expect($event->signature_valid)->toBeTrue()
        ->and($event->status)->toBe(WebhookEvent::STATUS_PENDING)
        ->and($event->prefix)->toBe('TEST')
        ->and($event->project)->toBe('Test Project');

    Queue::assertPushed(ForwardWebhookEvent::class, fn (ForwardWebhookEvent $job): bool => $job->webhookEvent->is($event));
});

it('rejects a webhook whose signature does not match', function () {
    Queue::fake();

    $payload = paystackPayload('TEST-abc123');
    [$body] = signedPaystackRequest($payload);

    $response = $this->call('POST', '/api/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => 'clearly-wrong',
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $response->assertNoContent();

    $event = WebhookEvent::query()->sole();

    expect($event->signature_valid)->toBeFalse()
        ->and($event->status)->toBe(WebhookEvent::STATUS_FAILED);

    Queue::assertNotPushed(ForwardWebhookEvent::class);
});

it('files a verified webhook for manual review when no project matches its reference', function () {
    Queue::fake();

    $payload = paystackPayload('UNKNOWNPREFIX-abc123');
    [$body, $signature] = signedPaystackRequest($payload);

    $this->call('POST', '/api/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertNoContent();

    $event = WebhookEvent::query()->sole();

    expect($event->status)->toBe(WebhookEvent::STATUS_UNMATCHED)
        ->and($event->prefix)->toBeNull();

    Queue::assertNotPushed(ForwardWebhookEvent::class);
});

it('does not queue a duplicate delivery when Paystack retries the same event', function () {
    Queue::fake();

    $payload = paystackPayload('TEST-abc123');
    [$body, $signature] = signedPaystackRequest($payload);

    $headers = [
        'HTTP_x-paystack-signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', '/api/webhooks/paystack', [], [], [], $headers, $body)->assertNoContent();
    $this->call('POST', '/api/webhooks/paystack', [], [], [], $headers, $body)->assertNoContent();

    expect(WebhookEvent::query()->count())->toBe(1);
    Queue::assertPushed(ForwardWebhookEvent::class, 1);
});

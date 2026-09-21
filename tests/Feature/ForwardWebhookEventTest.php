<?php

use App\Jobs\ForwardWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\WebhookRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
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

function makeWebhookEvent(string $reference): WebhookEvent
{
    return WebhookEvent::query()->create([
        'provider' => 'paystack',
        'event' => 'charge.success',
        'paystack_id' => '12345',
        'reference' => $reference,
        'prefix' => 'TEST',
        'project' => 'Test Project',
        'signature_valid' => true,
        'payload' => ['event' => 'charge.success', 'data' => ['reference' => $reference]],
        'status' => WebhookEvent::STATUS_PENDING,
    ]);
}

it('marks the event forwarded once the receiving project accepts it', function () {
    Http::fake([
        'test-project.example/*' => Http::response(null, 200),
    ]);

    $event = makeWebhookEvent('TEST-abc123');

    (new ForwardWebhookEvent($event))->handle(app(WebhookRouter::class));

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Gateway-Signature')
        && $request->url() === 'https://test-project.example/internal/paystack-webhook');

    expect($event->refresh()->status)->toBe(WebhookEvent::STATUS_FORWARDED)
        ->and($event->forwarded_at)->not->toBeNull();
});

it('throws so the queue retries when the receiving project rejects the forward', function () {
    Http::fake([
        'test-project.example/*' => Http::response('server error', 500),
    ]);

    $event = makeWebhookEvent('TEST-abc123');

    expect(fn () => (new ForwardWebhookEvent($event))->handle(app(WebhookRouter::class)))
        ->toThrow(RuntimeException::class);

    expect($event->refresh()->attempts)->toBe(1)
        ->and($event->status)->toBe(WebhookEvent::STATUS_PENDING);
});

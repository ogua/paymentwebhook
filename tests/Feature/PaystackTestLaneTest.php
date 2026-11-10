<?php

use App\Jobs\ForwardWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function laneEvent(string $reference, ?string $domain, int $id = 777): string
{
    $data = ['id' => $id, 'reference' => $reference, 'amount' => 100, 'status' => 'success'];

    if ($domain !== null) {
        $data['domain'] = $domain;
    }

    return json_encode(['event' => 'charge.success', 'data' => $data]);
}

function postLane($test, string $body, string $secret)
{
    return $test->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

beforeEach(function () {
    config([
        'paystack-gateway.paystack_secret_key' => 'sk_live_key',
        'paystack-gateway.paystack_test_secret_key' => 'sk_test_key',
        'paystack-gateway.test_prefixes' => ['OGSZ'],
        'paystack-gateway.default_forward_secret' => 'forward_secret',
        'paystack-gateway.projects' => [
            'OGSZ' => ['name' => 'Ogua Schoolz', 'forward_url' => 'https://v1.example/webhooks/paystack/gateway', 'forward_secret' => null],
            'POS' => ['name' => 'Ogua POS', 'forward_url' => 'https://pos.example/paystack/webhook/gateway', 'forward_secret' => null],
        ],
    ]);
});

it('keeps live events working exactly as before and marks them live', function () {
    Queue::fake();

    postLane($this, laneEvent('OGSZ-REQ-1-ABC', 'live'), 'sk_live_key')->assertNoContent();

    $event = WebhookEvent::query()->sole();
    expect($event->mode)->toBe('live')
        ->and($event->status)->toBe(WebhookEvent::STATUS_PENDING);
    Queue::assertPushed(ForwardWebhookEvent::class, 1);
});

it('accepts a test-key event that declares domain test for an opted-in project', function () {
    Queue::fake();

    postLane($this, laneEvent('OGSZ-REQ-1-ABC', 'test'), 'sk_test_key')->assertNoContent();

    $event = WebhookEvent::query()->sole();
    expect($event->signature_valid)->toBeTrue()
        ->and($event->mode)->toBe('test')
        ->and($event->status)->toBe(WebhookEvent::STATUS_PENDING);
    Queue::assertPushed(ForwardWebhookEvent::class, 1);
});

it('rejects a test-key event that claims to be live', function () {
    Queue::fake();

    postLane($this, laneEvent('OGSZ-REQ-1-ABC', 'live'), 'sk_test_key')->assertNoContent();

    $event = WebhookEvent::query()->sole();
    expect($event->signature_valid)->toBeFalse()
        ->and($event->mode)->toBeNull()
        ->and($event->status)->toBe(WebhookEvent::STATUS_FAILED);
    Queue::assertNotPushed(ForwardWebhookEvent::class);
});

it('rejects a test-key event with no domain at all', function () {
    Queue::fake();

    postLane($this, laneEvent('OGSZ-REQ-1-ABC', null), 'sk_test_key')->assertNoContent();

    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEvent::STATUS_FAILED);
    Queue::assertNotPushed(ForwardWebhookEvent::class);
});

it('keeps but never forwards a test event for a project outside the test lane', function () {
    Queue::fake();

    postLane($this, laneEvent('POS-123', 'test'), 'sk_test_key')->assertNoContent();

    $event = WebhookEvent::query()->sole();
    expect($event->mode)->toBe('test')
        ->and($event->status)->toBe(WebhookEvent::STATUS_UNMATCHED)
        ->and($event->last_error)->toContain('PAYSTACK_TEST_PREFIXES');
    Queue::assertNotPushed(ForwardWebhookEvent::class);
});

it('ignores test-signed events entirely when no test key is configured', function () {
    Queue::fake();
    config(['paystack-gateway.paystack_test_secret_key' => null]);

    postLane($this, laneEvent('OGSZ-REQ-1-ABC', 'test'), 'sk_test_key')->assertNoContent();

    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEvent::STATUS_FAILED);
    Queue::assertNotPushed(ForwardWebhookEvent::class);
});

it('tells the receiving project which mode the event was signed in', function () {
    Http::fake(['*' => Http::response('', 200)]);

    postLane($this, laneEvent('OGSZ-REQ-1-ABC', 'test'), 'sk_test_key')->assertNoContent();

    Http::assertSent(fn (HttpRequest $request): bool => $request->header('X-Gateway-Mode') === ['test']);
    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEvent::STATUS_FORWARDED);
});

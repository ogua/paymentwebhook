<?php

use App\Models\WebhookEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * End-to-end check for every real project in config/paystack-gateway.php,
 * using the forward URLs and secrets from .env: a signed Paystack webhook
 * carrying that project's prefix must land at that project's configured
 * forward_url, and only there, signed with its own secret. Outbound HTTP
 * is faked, so no live project ever receives these test payments.
 */

dataset('projects', [
    'OGS2' => ['OGS2', 'Ogua School v2'],
    'OGSZ' => ['OGSZ', 'Ogua Schoolz'],
    'POS' => ['POS', 'Ogua POS'],
    'OGCARE' => ['OGCARE', 'OguaCare+'],
    'SUSU' => ['SUSU', 'SusuApp'],
    'SUSULIC' => ['SUSULIC', 'SusuApp (desktop license)'],
    'TERT' => ['TERT', 'Ogua Tertiary'],
]);

beforeEach(function () {
    // Only the inbound Paystack key is swapped, so the test can sign its own
    // webhooks without the real account secret.
    config(['paystack-gateway.paystack_secret_key' => 'whsec_test']);

    Http::fake(['*' => Http::response(null, 200)]);
});

function postSignedPaystackWebhook(string $reference, string $event = 'charge.success'): string
{
    $body = json_encode([
        'event' => $event,
        'data' => [
            'id' => random_int(1, PHP_INT_MAX),
            'reference' => $reference,
            'amount' => 50000,
            'status' => 'success',
        ],
    ]);

    test()->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'whsec_test'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertNoContent();

    return $body;
}

function forwardSecretFor(string $prefix): string
{
    return (string) (config("paystack-gateway.projects.{$prefix}.forward_secret")
        ?? config('paystack-gateway.default_forward_secret'));
}

it('has a routing test for every configured project', function () {
    expect(array_keys(config('paystack-gateway.projects')))
        ->toEqualCanonicalizing(['OGS2', 'OGSZ', 'POS', 'OGCARE', 'SUSU', 'SUSULIC', 'TERT']);
});

it('has a forward URL and secret set in .env', function (string $prefix) {
    expect(config("paystack-gateway.projects.{$prefix}.forward_url"))->toBeString()->not->toBeEmpty()
        ->and(forwardSecretFor($prefix))->not->toBeEmpty();
})->with('projects');

it('gives every project its own forward URL', function () {
    $urls = array_column(config('paystack-gateway.projects'), 'forward_url');

    expect($urls)->toHaveCount(count(array_unique($urls)));
});

it('forwards a signed webhook to the owning project', function (string $prefix, string $name) {
    $forwardUrl = config("paystack-gateway.projects.{$prefix}.forward_url");
    $reference = "{$prefix}-".bin2hex(random_bytes(4));
    $body = postSignedPaystackWebhook($reference);

    $event = WebhookEvent::query()->sole();

    expect($event->signature_valid)->toBeTrue()
        ->and($event->prefix)->toBe($prefix)
        ->and($event->project)->toBe($name)
        ->and($event->status)->toBe(WebhookEvent::STATUS_FORWARDED)
        ->and($event->attempts)->toBe(1);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $forwardUrl
        && $request->body() === $body
        && $request->header('X-Gateway-Signature')[0] === hash_hmac('sha256', $body, forwardSecretFor($prefix))
        && $request->header('x-paystack-signature')[0] === hash_hmac('sha512', $body, 'whsec_test')
        && $request->header('X-Gateway-Reference')[0] === $reference);
})->with('projects');

it('routes a lowercase prefix to the same project', function (string $prefix, string $name) {
    postSignedPaystackWebhook(strtolower($prefix).'-abc123');

    expect(WebhookEvent::query()->sole()->project)->toBe($name);
    Http::assertSent(fn (Request $request): bool => $request->url() === config("paystack-gateway.projects.{$prefix}.forward_url"));
})->with('projects');

it('handles transfer events as well as charges', function (string $prefix) {
    postSignedPaystackWebhook("{$prefix}-trf001", 'transfer.success');

    expect(WebhookEvent::query()->sole()->status)->toBe(WebhookEvent::STATUS_FORWARDED);
    Http::assertSent(fn (Request $request): bool => $request->header('X-Gateway-Event')[0] === 'transfer.success');
})->with('projects');

it('falls back to the default forward secret when a project has none', function (string $prefix) {
    config(['paystack-gateway.default_forward_secret' => 'default_forward_secret']);
    config(["paystack-gateway.projects.{$prefix}.forward_secret" => null]);

    $body = postSignedPaystackWebhook("{$prefix}-abc123");

    Http::assertSent(fn (Request $request): bool => $request->header('X-Gateway-Signature')[0] === hash_hmac('sha256', $body, 'default_forward_secret'));
})->with('projects');

it('does not confuse SUSU with SUSULIC', function () {
    postSignedPaystackWebhook('SUSU-abc123');
    postSignedPaystackWebhook('SUSULIC-abc123');

    expect(WebhookEvent::query()->pluck('project', 'reference')->all())->toBe([
        'SUSU-abc123' => 'SusuApp',
        'SUSULIC-abc123' => 'SusuApp (desktop license)',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === config('paystack-gateway.projects.SUSU.forward_url')
        && $request->header('X-Gateway-Reference')[0] === 'SUSU-abc123');
    Http::assertSent(fn (Request $request): bool => $request->url() === config('paystack-gateway.projects.SUSULIC.forward_url')
        && $request->header('X-Gateway-Reference')[0] === 'SUSULIC-abc123');
});

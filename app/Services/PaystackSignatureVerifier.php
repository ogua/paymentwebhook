<?php

namespace App\Services;

/**
 * Confirms a webhook body actually came from Paystack before anything else
 * touches it — Paystack signs the raw request body with HMAC SHA512 using
 * the account's secret key, sent back as the `x-paystack-signature` header.
 *
 * Live events are signed with the live secret. Test-mode events are signed
 * with the test secret, and are only recognised when the optional test key
 * is configured AND the payload itself says it is a test event
 * (`data.domain === "test"`), so a test key can never pass a live payment.
 */
class PaystackSignatureVerifier
{
    public const MODE_LIVE = 'live';

    public const MODE_TEST = 'test';

    public function verify(string $rawBody, ?string $signature): bool
    {
        return $this->mode($rawBody, $signature) !== null;
    }

    /**
     * Which key signed this body: "live", "test", or null when neither did.
     */
    public function mode(string $rawBody, ?string $signature): ?string
    {
        if (blank($signature)) {
            return null;
        }

        if ($this->matches($rawBody, $signature, (string) config('paystack-gateway.paystack_secret_key'))) {
            return self::MODE_LIVE;
        }

        if ($this->matches($rawBody, $signature, (string) config('paystack-gateway.paystack_test_secret_key'))
            && $this->declaresTestDomain($rawBody)) {
            return self::MODE_TEST;
        }

        return null;
    }

    private function matches(string $rawBody, string $signature, string $secretKey): bool
    {
        if ($secretKey === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secretKey), $signature);
    }

    private function declaresTestDomain(string $rawBody): bool
    {
        $payload = json_decode($rawBody, true);

        return is_array($payload) && (($payload['data']['domain'] ?? null) === 'test');
    }
}

<?php

namespace App\Services;

/**
 * Confirms a webhook body actually came from Paystack before anything else
 * touches it — Paystack signs the raw request body with HMAC SHA512 using
 * the account's secret key, sent back as the `x-paystack-signature` header.
 */
class PaystackSignatureVerifier
{
    public function verify(string $rawBody, ?string $signature): bool
    {
        if (blank($signature)) {
            return false;
        }

        $secretKey = (string) config('paystack-gateway.paystack_secret_key');

        if ($secretKey === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawBody, $secretKey);

        return hash_equals($expected, $signature);
    }
}

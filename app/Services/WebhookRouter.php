<?php

namespace App\Services;

/**
 * Decides which of the projects sharing this Paystack account owns a
 * webhook, purely from the reference's prefix — the convention every
 * project's transaction-initiation code is expected to follow (e.g. a
 * reference of "OGS2-8f3a2c1" belongs to the "OGS2" entry in
 * config/paystack-gateway.php).
 */
class WebhookRouter
{
    /**
     * @return array{prefix: string, name: string, forward_url: ?string, forward_secret: ?string}|null
     *                                                                                                 null when the reference carries no recognised prefix — the
     *                                                                                                 caller should file that event for manual review rather than
     *                                                                                                 guess where it belongs.
     */
    public function resolve(?string $reference): ?array
    {
        if (blank($reference) || ! str_contains($reference, '-')) {
            return null;
        }

        $prefix = strtoupper(strstr($reference, '-', true));

        /** @var array<string, array{name: string, forward_url: ?string, forward_secret: ?string}> $projects */
        $projects = config('paystack-gateway.projects', []);

        if (! isset($projects[$prefix])) {
            return null;
        }

        return [
            'prefix' => $prefix,
            'name' => $projects[$prefix]['name'],
            'forward_url' => $projects[$prefix]['forward_url'],
            'forward_secret' => $projects[$prefix]['forward_secret'] ?? config('paystack-gateway.default_forward_secret'),
        ];
    }
}

<?php

/**
 * `next_sequence`-style registry for one thing: which project owns a
 * reference, and where to forward its webhook once decoded.
 *
 * Every project sharing this Paystack account must prefix the references it
 * generates with its key here, e.g. a reference of "OGS2-8f3a2c1" routes to
 * the "OGS2" entry. Add a project by adding one entry — nothing else in the
 * gateway needs to change.
 */
return [

    'paystack_secret_key' => env('PAYSTACK_SECRET_KEY'),

    /*
     * Test lane (optional). Paystack signs test-mode events with the test
     * secret. They are accepted only when the payload says `domain: test`,
     * and forwarded only for the prefixes listed here; a test event for any
     * other project is kept for audit but never delivered. Leave the key
     * empty to switch the lane off.
     */
    'paystack_test_secret_key' => env('PAYSTACK_TEST_SECRET_KEY'),

    'test_prefixes' => array_values(array_filter(array_map(
        fn ($prefix) => strtoupper(trim($prefix)),
        explode(',', (string) env('PAYSTACK_TEST_PREFIXES', ''))
    ))),

    /*
     * The shared secret this gateway signs its own outbound forward with,
     * so a receiving project can tell a forwarded webhook actually came from
     * here and not from an arbitrary caller hitting its internal endpoint.
     * Each project may instead define its own per-project secret below.
     */
    'default_forward_secret' => env('WEBHOOK_GATEWAY_FORWARD_SECRET'),

    'projects' => [

        'OGS2' => [
            'name' => 'Ogua School v2',
            'forward_url' => env('OGS2_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('OGS2_WEBHOOK_FORWARD_SECRET'),
        ],

        'OGSZ' => [
            'name' => 'Ogua Schoolz',
            'forward_url' => env('OGSZ_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('OGSZ_WEBHOOK_FORWARD_SECRET'),
        ],

        'POS' => [
            'name' => 'Ogua POS',
            'forward_url' => env('POS_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('POS_WEBHOOK_FORWARD_SECRET'),
        ],

        'OGCARE' => [
            'name' => 'OguaCare+',
            'forward_url' => env('OGCARE_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('OGCARE_WEBHOOK_FORWARD_SECRET'),
        ],

        'SUSU' => [
            'name' => 'SusuApp',
            'forward_url' => env('SUSU_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('SUSU_WEBHOOK_FORWARD_SECRET'),
        ],

        // Kept as a separate prefix/entry from 'SUSU' above (not a sub-path
        // of it) because SusuApp itself deliberately keeps desktop-license
        // sales on a different internal endpoint from susu money-movement,
        // so a license sale's fulfillment path never touches that code.
        'SUSULIC' => [
            'name' => 'SusuApp (desktop license)',
            'forward_url' => env('SUSULIC_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('SUSULIC_WEBHOOK_FORWARD_SECRET'),
        ],

        'TERT' => [
            'name' => 'Ogua Tertiary',
            'forward_url' => env('TERT_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('TERT_WEBHOOK_FORWARD_SECRET'),
        ],

    ],

];

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

        'OGF' => [
            'name' => 'Ogua Finance',
            'forward_url' => env('OGF_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('OGF_WEBHOOK_FORWARD_SECRET'),
        ],

        'TERT' => [
            'name' => 'Ogua Tertiary',
            'forward_url' => env('TERT_WEBHOOK_FORWARD_URL'),
            'forward_secret' => env('TERT_WEBHOOK_FORWARD_SECRET'),
        ],

    ],

];

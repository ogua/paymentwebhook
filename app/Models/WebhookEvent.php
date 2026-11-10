<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per inbound provider webhook — the permanent audit log of every
 * event this gateway ever received, whether or not it could be routed.
 *
 * @property string $provider
 * @property string $event
 * @property string|null $paystack_id
 * @property string|null $reference
 * @property string|null $prefix
 * @property string|null $project
 * @property bool $signature_valid
 * @property string|null $mode "live" or "test": which Paystack key signed it
 * @property string|null $paystack_signature
 * @property string $raw_body
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $forwarded_at
 */
class WebhookEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_FORWARDED = 'forwarded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNMATCHED = 'unmatched';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'attempts' => 'integer',
            'forwarded_at' => 'datetime',
        ];
    }
}

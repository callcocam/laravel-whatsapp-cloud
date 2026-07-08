<?php

namespace Callcocam\WhatsAppCloud\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A delivery status (sent/delivered/read/failed) arrived on the webhook, tied
 * back to a sent message by its `id` (the wamid). The app listens to persist or
 * log; the package neither stores nor acts on it.
 */
class WhatsAppStatusReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $status  a single entry of `value.statuses[]`
     * @param  array<string, mixed>  $value  the full `changes[].value` (metadata, …)
     */
    public function __construct(
        public readonly array $status,
        public readonly array $value,
        public readonly ?string $phoneNumberId = null,
    ) {}
}

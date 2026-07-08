<?php

namespace Callcocam\WhatsAppCloud\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An inbound WhatsApp message arrived on the webhook. The package only parses
 * and dispatches — the app decides what (if anything) to do. `phoneNumberId`
 * lets a listener resolve which tenant/number received it.
 */
class WhatsAppMessageReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $message  a single entry of `value.messages[]`
     * @param  array<string, mixed>  $value  the full `changes[].value` (metadata, contacts, …)
     */
    public function __construct(
        public readonly array $message,
        public readonly array $value,
        public readonly ?string $phoneNumberId = null,
    ) {}
}

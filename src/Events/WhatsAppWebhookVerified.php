<?php

namespace Callcocam\WhatsAppCloud\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Meta's one-time hub-challenge handshake succeeded (the callback URL was
 * registered). Handy for an app that wants to log/notify when the webhook is
 * (re)verified.
 */
class WhatsAppWebhookVerified
{
    use Dispatchable;

    public function __construct(
        public readonly string $challenge,
    ) {}
}

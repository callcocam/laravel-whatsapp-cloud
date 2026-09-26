<?php

namespace Callcocam\WhatsAppCloud\Events;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A number was connected (or reconnected) through Embedded Signup and saved.
 *
 * The package does not know which tenant the number belongs to. A multi-tenant
 * app listens to this and ties the row to the current tenant, e.g.
 * `$event->number->update(['key' => auth()->user()->team->slug])`.
 */
class WhatsAppNumberConnected
{
    use Dispatchable;

    /**
     * @param  list<string>  $warnings  non-fatal problems (webhook subscription, registration)
     */
    public function __construct(
        public readonly Model&WhatsAppCredentials $number,
        public readonly array $warnings = [],
    ) {}
}

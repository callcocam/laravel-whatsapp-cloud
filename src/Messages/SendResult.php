<?php

namespace Callcocam\WhatsAppCloud\Messages;

/**
 * The outcome of a send. Meta returns the `wamid` that later ties a delivery
 * `status` webhook back to this message (null when none was returned).
 */
final class SendResult
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $messageId = null,
        public readonly string $status = 'sent',
    ) {}

    public static function sent(string $provider, ?string $messageId = null): self
    {
        return new self($provider, $messageId, 'sent');
    }
}

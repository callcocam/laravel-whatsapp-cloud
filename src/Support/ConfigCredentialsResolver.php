<?php

namespace Callcocam\WhatsAppCloud\Support;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;

/**
 * The package default resolver: it ignores the context and always returns the
 * config `default` credentials. Good enough for dev and single-tenant apps.
 * Multi-tenant apps rebind {@see WhatsAppCredentialsResolver} with their own.
 */
final class ConfigCredentialsResolver implements WhatsAppCredentialsResolver
{
    /**
     * @param  array<string, mixed>  $default  the `whatsapp-cloud.default` config
     */
    public function __construct(private readonly array $default) {}

    public function resolve(mixed $context): ?WhatsAppCredentials
    {
        if ($context instanceof WhatsAppCredentials) {
            return $context;
        }

        return ArrayCredentials::fromArray($this->default);
    }
}

<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\Support\ConfigCredentialsResolver;

/**
 * Resolves an arbitrary tenant context (a Team model, a key string, whatever the
 * app passes to `WhatsApp::for($context)`) into the {@see WhatsAppCredentials}
 * to send with — or null when that context has no usable number.
 *
 * The app binds its own implementation in a service provider; the package ships
 * a config-backed default ({@see ConfigCredentialsResolver}).
 */
interface WhatsAppCredentialsResolver
{
    public function resolve(mixed $context): ?WhatsAppCredentials;
}

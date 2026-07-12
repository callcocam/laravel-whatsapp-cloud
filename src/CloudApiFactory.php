<?php

namespace Callcocam\WhatsAppCloud;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;

/**
 * Builds a {@see CloudApiClient} from a set of resolved {@see WhatsAppCredentials}.
 * The Graph version and the shared template registry come from config; the
 * per-number identity (phone number id + access token) comes from the
 * credentials, which may also override the Graph version.
 */
class CloudApiFactory
{
    public function __construct(
        protected readonly string $graphVersion,
        protected readonly TemplateRegistry $templates,
    ) {}

    public function make(WhatsAppCredentials $credentials): CloudApiClient
    {
        return new CloudApiClient(
            graphVersion: $credentials->graphVersion() ?? $this->graphVersion,
            phoneNumberId: $credentials->phoneNumberId(),
            accessToken: $credentials->accessToken(),
            templates: $this->templates,
            // Left null on purpose: the client resolves the transport lazily, so
            // the driver in force is the one at send time, not at build time.
            transport: null,
        );
    }
}

<?php

namespace Callcocam\WhatsAppCloud;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppNotConfiguredException;
use Callcocam\WhatsAppCloud\Support\ArrayCredentials;
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;
use Callcocam\WhatsAppCloud\Templates\TemplateManager;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;

/**
 * The single entrypoint the app talks to (via the `WhatsApp` facade or by
 * injecting this class). `for($tenant)` resolves the tenant's credentials
 * through the bound {@see WhatsAppCredentialsResolver} and hands back a
 * {@see CloudApiClient}; `for()` with no argument uses the config `default`
 * credentials (dev / single-tenant).
 */
class WhatsAppManager
{
    /**
     * @param  array<string, mixed>  $defaultCredentials  the `whatsapp-cloud.default` config
     */
    public function __construct(
        protected readonly CloudApiFactory $factory,
        protected readonly WhatsAppCredentialsResolver $resolver,
        protected readonly TemplateRegistry $registry,
        protected readonly array $defaultCredentials,
    ) {}

    /**
     * The sender for a tenant context, or the config default when none is given.
     *
     * @throws WhatsAppNotConfiguredException when no usable credentials resolve
     */
    public function for(mixed $context = null): CloudApiClient
    {
        return $this->factory->make($this->credentials($context));
    }

    /**
     * The resolved credentials for a tenant context (config default when none is
     * given). Useful for the template-management API, which needs the WABA id.
     *
     * @throws WhatsAppNotConfiguredException when no usable credentials resolve
     */
    public function credentials(mixed $context = null): WhatsAppCredentials
    {
        $credentials = $context === null
            ? ArrayCredentials::fromArray($this->defaultCredentials)
            : $this->resolver->resolve($context);

        if (! $credentials instanceof WhatsAppCredentials) {
            throw new WhatsAppNotConfiguredException(
                'No WhatsApp Cloud credentials resolved for the given context.',
            );
        }

        return $credentials;
    }

    /**
     * The Meta template-management API (create/list/get/delete/send) bound to a
     * tenant's WABA. Used by the `whatsapp:template:*` commands.
     *
     * @throws WhatsAppNotConfiguredException when the context has no WABA id
     */
    public function templateApi(mixed $context = null): TemplateManager
    {
        $credentials = $this->credentials($context);
        $wabaId = $credentials->wabaId();

        if (blank($wabaId)) {
            throw new WhatsAppNotConfiguredException(
                'The resolved WhatsApp credentials have no WABA id (required for template management).',
            );
        }

        return new TemplateManager(
            graphVersion: $credentials->graphVersion() ?? $this->defaultGraphVersion(),
            wabaId: (string) $wabaId,
            accessToken: $credentials->accessToken(),
            phoneNumberId: $credentials->phoneNumberId(),
        );
    }

    private function defaultGraphVersion(): string
    {
        return (string) config('whatsapp-cloud.graph_version', 'v21.0');
    }

    /**
     * Register (or override) a template definition at runtime.
     */
    public function registerTemplate(string $key, MetaTemplate $template): self
    {
        $this->registry->register($key, $template);

        return $this;
    }

    /**
     * The shared template registry (config + runtime registrations).
     */
    public function templates(): TemplateRegistry
    {
        return $this->registry;
    }
}

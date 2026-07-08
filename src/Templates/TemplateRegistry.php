<?php

namespace Callcocam\WhatsAppCloud\Templates;

use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;

/**
 * Maps each business-initiated app message key to its approved Meta template.
 * Definitions come from `config('whatsapp-cloud.templates')`, plus any
 * registered programmatically via {@see register()} (which win over config for
 * the same key). Keys not registered here cannot be sent (the client throws) — a
 * deliberate guard so an un-approved template never silently fails at Meta.
 *
 * The caller passes a {@see TemplateMessage} carrying params keyed by the names
 * in each template's ordered `bodyParams`.
 */
class TemplateRegistry
{
    /**
     * @var array<string, MetaTemplate>
     */
    private array $registered = [];

    private bool $booted = false;

    /**
     * @param  array<string, array<string, mixed>>  $config  the raw config map (key => definition array)
     */
    public function __construct(private readonly array $config = []) {}

    /**
     * Register (or override) a template definition at runtime.
     */
    public function register(string $key, MetaTemplate $template): void
    {
        $this->registered[$key] = $template;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function resolve(string $key): MetaTemplate
    {
        return $this->all()[$key]
            ?? throw new CloudApiException("WhatsApp template not registered for key [{$key}].");
    }

    /**
     * @return array<string, MetaTemplate>
     */
    public function all(): array
    {
        $this->boot();

        return $this->registered;
    }

    /**
     * Materialize the config definitions once, without clobbering anything that
     * was already registered programmatically for the same key.
     */
    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->config as $key => $definition) {
            $this->registered[$key] ??= MetaTemplate::fromArray((array) $definition);
        }

        $this->booted = true;
    }
}

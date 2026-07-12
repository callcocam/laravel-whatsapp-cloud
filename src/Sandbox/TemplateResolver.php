<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Finds a template's real content so the sandbox can draw a real bubble.
 *
 * Local definition file first, Meta second — and that order is the feature. It
 * means the sandbox works offline, and works on a template that has not been
 * submitted yet, which is precisely when you still want to change your mind about
 * the wording and the buttons.
 *
 * Meta is only consulted for a template with no local definition (one created
 * straight from the panel, say). If that lookup fails — no WABA, no token, no
 * network — we return null and the caller degrades to showing the raw name and
 * params. A sandbox that refuses to open because a token expired would be worse
 * than useless.
 */
final class TemplateResolver
{
    public function __construct(
        private readonly TemplateDefinitions $definitions,
        private readonly WhatsAppManager $whatsapp,
    ) {}

    public function resolve(string $name, string $language, mixed $tenant = null): ?ResolvedTemplate
    {
        if ($definition = $this->definitions->find($name, $language)) {
            return new ResolvedTemplate(
                name: $name,
                language: $language,
                components: array_values((array) ($definition['components'] ?? [])),
                source: ResolvedTemplate::SOURCE_DEFINITION,
            );
        }

        return $this->fromMeta($name, $language, $tenant);
    }

    private function fromMeta(string $name, string $language, mixed $tenant): ?ResolvedTemplate
    {
        $components = Cache::remember(
            "whatsapp-cloud:sandbox:template:{$name}:{$language}",
            now()->addHour(),
            function () use ($name): array {
                try {
                    $template = $this->whatsapp->templateApi()->getByName($name);
                } catch (Throwable) {
                    // No WABA, expired token, offline. Not fatal — the sandbox
                    // just cannot draw this particular bubble in full.
                    return [];
                }

                return array_values((array) ($template['components'] ?? []));
            },
        );

        if ($components === []) {
            return null;
        }

        return new ResolvedTemplate(
            name: $name,
            language: $language,
            components: $components,
            source: ResolvedTemplate::SOURCE_META,
        );
    }
}

<?php

namespace Callcocam\WhatsAppCloud\Templates;

use Callcocam\WhatsAppCloud\Messages\TemplateMessage;

/**
 * One entry of the {@see TemplateRegistry}: the Meta-side identity of an app
 * message key. `bodyParams` is the ORDERED list of param names the approved
 * template's body expects ({{1}}, {{2}}…); the client pulls each value from the
 * {@see TemplateMessage} params in this order.
 */
final class MetaTemplate
{
    /**
     * @param  list<string>  $bodyParams
     */
    public function __construct(
        public readonly string $name,
        public readonly string $language = 'pt_BR',
        public readonly string $category = 'utility',
        public readonly array $bodyParams = [],
    ) {}

    /**
     * Build from a config array: `['name' => ..., 'language' => ..., 'category'
     * => ..., 'params' => [...]]`.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self(
            name: (string) ($definition['name'] ?? ''),
            language: (string) ($definition['language'] ?? 'pt_BR'),
            category: (string) ($definition['category'] ?? 'utility'),
            bodyParams: array_values(array_map('strval', (array) ($definition['params'] ?? []))),
        );
    }
}

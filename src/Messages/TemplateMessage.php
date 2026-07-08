<?php

namespace Callcocam\WhatsAppCloud\Messages;

use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;

/**
 * A business-initiated message expressed by intent. It carries the app's message
 * key + ordered params, which the {@see TemplateRegistry} maps to a pre-approved
 * Meta template.
 */
final class TemplateMessage
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {}

    /**
     * @param  array<string, string|int>  $params
     */
    public static function make(string $key, array $params = []): self
    {
        return new self($key, $params);
    }
}

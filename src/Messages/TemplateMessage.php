<?php

namespace Callcocam\WhatsAppCloud\Messages;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;

/**
 * A business-initiated message expressed by intent. It carries the app's message
 * key + ordered params, which the {@see TemplateRegistry} maps to a pre-approved
 * Meta template, and optionally the document of a DOCUMENT header (a media id
 * from {@see CloudApiClient::uploadMedia()} plus the file name the person sees).
 */
final class TemplateMessage
{
    /**
     * @param  array<string, string|int>  $params
     * @param  array{id: string, filename: string}|null  $document
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        public readonly ?array $document = null,
    ) {}

    /**
     * @param  array<string, string|int>  $params
     */
    public static function make(string $key, array $params = []): self
    {
        return new self($key, $params);
    }

    /**
     * The same message with a document in the template's DOCUMENT header.
     */
    public function withDocument(string $mediaId, string $filename): self
    {
        return new self($this->key, $this->params, ['id' => $mediaId, 'filename' => $filename]);
    }
}

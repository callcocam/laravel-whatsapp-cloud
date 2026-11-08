<?php

namespace Callcocam\WhatsAppCloud\Messages;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;

/**
 * A business-initiated message expressed by intent. It carries the app's message
 * key + ordered params, which the {@see TemplateRegistry} maps to a pre-approved
 * Meta template, and optionally the document of a DOCUMENT header (a media id
 * from {@see CloudApiClient::uploadMedia()} plus the file name the person sees)
 * and the payload each QUICK_REPLY button returns when tapped (so the app knows
 * WHICH record the tap is about: Meta echoes it in `button.payload`).
 */
final class TemplateMessage
{
    /**
     * @param  array<string, string|int>  $params
     * @param  array{id: string, filename: string}|null  $document
     * @param  array<int, string>  $buttonPayloads  payload by quick-reply button index (0-based)
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        public readonly ?array $document = null,
        public readonly array $buttonPayloads = [],
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
        return new self($this->key, $this->params, ['id' => $mediaId, 'filename' => $filename], $this->buttonPayloads);
    }

    /**
     * The same message with the payload each quick-reply button returns, by its
     * index in the template (0-based). Meta caps a payload at 1000 chars.
     *
     * @param  array<int, string>  $payloads
     */
    public function withButtonPayloads(array $payloads): self
    {
        return new self($this->key, $this->params, $this->document, $payloads);
    }
}

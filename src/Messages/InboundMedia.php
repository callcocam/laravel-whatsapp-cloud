<?php

namespace Callcocam\WhatsAppCloud\Messages;

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;

/**
 * The media part of an inbound message ({@see WhatsAppMessageReceived::$message}):
 * which kind, the id to download it with, and what the sender typed along with it.
 * Only `image` and `document` are read; anything else (audio, sticker, video) is
 * not media an app here asks for.
 */
final class InboundMedia
{
    public const IMAGE = 'image';

    public const DOCUMENT = 'document';

    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $mimeType,
        public readonly ?string $sha256 = null,
        public readonly ?string $filename = null,
        public readonly ?string $caption = null,
    ) {}

    /**
     * @param  array<string, mixed>  $message  a single entry of `value.messages[]`
     */
    public static function fromMessage(array $message): ?self
    {
        $type = $message['type'] ?? null;

        if (! in_array($type, [self::IMAGE, self::DOCUMENT], true)) {
            return null;
        }

        $media = $message[$type] ?? null;

        if (! is_array($media) || ! is_string($media['id'] ?? null) || $media['id'] === '') {
            return null;
        }

        return new self(
            type: $type,
            id: $media['id'],
            mimeType: is_string($media['mime_type'] ?? null) ? $media['mime_type'] : 'application/octet-stream',
            sha256: is_string($media['sha256'] ?? null) ? $media['sha256'] : null,
            filename: is_string($media['filename'] ?? null) ? $media['filename'] : null,
            caption: is_string($media['caption'] ?? null) ? $media['caption'] : null,
        );
    }
}

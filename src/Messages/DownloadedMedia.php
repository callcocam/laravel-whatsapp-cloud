<?php

namespace Callcocam\WhatsAppCloud\Messages;

/**
 * The bytes of a received file, with the type Meta declared and the hash of what
 * actually arrived (not the one Meta announced), so the app can refuse a file it
 * already has.
 */
final class DownloadedMedia
{
    public readonly string $sha256;

    public readonly int $size;

    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
    ) {
        $this->sha256 = hash('sha256', $contents);
        $this->size = strlen($contents);
    }
}

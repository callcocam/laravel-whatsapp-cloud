<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\Contracts\MediaDownloader;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Exceptions\MediaDownloadException;
use Callcocam\WhatsAppCloud\Exceptions\SandboxException;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;

/**
 * The download that goes nowhere. A file put here under an id
 * ({@see self::put()}) comes back as is, so a test or the sandbox can hand the app
 * the exact photo it needs; any other id gets the bundled sample: a PDF when the
 * id was made by {@see InboundPayloadFactory::document()}, an Ogg/Opus voice note
 * for {@see InboundPayloadFactory::audio()}, a JPEG otherwise. Same
 * size and type checks as the real download, same production guard as
 * {@see SandboxTransport}.
 */
class SandboxMediaDownloader implements MediaDownloader
{
    public const CACHE_PREFIX = 'whatsapp-cloud:sandbox-media:';

    public function __construct(Application $app)
    {
        if ($app->isProduction()) {
            throw new SandboxException(
                'The WhatsApp sandbox driver refuses to run in production. '
                .'Set WHATSAPP_CLOUD_DRIVER=cloud (and run `config:clear` and `queue:restart`).',
            );
        }
    }

    /**
     * Make `$mediaId` download as these bytes (for a day).
     */
    public static function put(string $mediaId, string $contents, string $mimeType): void
    {
        Cache::put(self::CACHE_PREFIX.$mediaId, ['contents' => base64_encode($contents), 'mime_type' => $mimeType], now()->addDay());
    }

    public function download(WhatsAppCredentials $credentials, string $mediaId, int $maxBytes, array $allowedMimeTypes = []): DownloadedMedia
    {
        $stored = Cache::get(self::CACHE_PREFIX.$mediaId);

        $media = is_array($stored)
            ? new DownloadedMedia((string) base64_decode((string) $stored['contents'], true), (string) $stored['mime_type'])
            : $this->sample($mediaId);

        if ($allowedMimeTypes !== [] && ! in_array($media->mimeType, $allowedMimeTypes, true)) {
            throw MediaDownloadException::typeNotAllowed($media->mimeType);
        }

        if ($media->size > $maxBytes) {
            throw MediaDownloadException::tooLarge($media->size, $maxBytes);
        }

        return $media;
    }

    private function sample(string $mediaId): DownloadedMedia
    {
        [$file, $mimeType] = match (true) {
            str_starts_with($mediaId, InboundPayloadFactory::DOCUMENT_MEDIA_PREFIX) => ['sample.pdf', 'application/pdf'],
            str_starts_with($mediaId, InboundPayloadFactory::AUDIO_MEDIA_PREFIX) => ['sample.ogg', 'audio/ogg; codecs=opus'],
            default => ['sample.jpg', 'image/jpeg'],
        };

        return new DownloadedMedia((string) file_get_contents(__DIR__.'/../../resources/sandbox/'.$file), $mimeType);
    }
}

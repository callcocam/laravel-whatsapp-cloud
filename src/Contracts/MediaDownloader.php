<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\MediaDownloadException;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;

/**
 * Fetches a file someone SENT to the number (a photo, a PDF), by the media id the
 * webhook carries. Meta answers `GET /{media-id}` with a URL valid for about five
 * minutes, reachable only with the same access token.
 *
 * Chosen by the same `whatsapp-cloud.driver` as {@see MessageTransport}, resolved
 * lazily by {@see CloudApiClient::downloadMedia()}: under the sandbox nothing
 * leaves the machine.
 */
interface MediaDownloader
{
    /**
     * @param  int  $maxBytes  refuse anything larger (checked before AND after the download)
     * @param  list<string>  $allowedMimeTypes  empty accepts any type
     *
     * @throws MediaDownloadException when the file is too large or of a type not allowed
     * @throws CloudApiException on any Meta or network failure
     */
    public function download(WhatsAppCredentials $credentials, string $mediaId, int $maxBytes, array $allowedMimeTypes = []): DownloadedMedia;
}

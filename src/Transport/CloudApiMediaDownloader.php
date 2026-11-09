<?php

namespace Callcocam\WhatsAppCloud\Transport;

use Callcocam\WhatsAppCloud\Contracts\MediaDownloader;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\MediaDownloadException;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The real download, in two steps: `GET /{media-id}` answers the temporary URL,
 * its type and size; then that URL, with the same token. Size and type are checked
 * before downloading, and the size again on what arrived.
 */
class CloudApiMediaDownloader implements MediaDownloader
{
    public function download(WhatsAppCredentials $credentials, string $mediaId, int $maxBytes, array $allowedMimeTypes = []): DownloadedMedia
    {
        $graphVersion = $credentials->graphVersion() ?? (string) config('whatsapp-cloud.graph_version', 'v21.0');

        try {
            $lookup = Http::withToken($credentials->accessToken())
                ->acceptJson()
                ->timeout(15)
                ->get("https://graph.facebook.com/{$graphVersion}/".rawurlencode($mediaId), [
                    'phone_number_id' => $credentials->phoneNumberId(),
                ]);
        } catch (ConnectionException $exception) {
            throw new CloudApiException('Could not connect to the WhatsApp Cloud API.', previous: $exception);
        }

        if ($lookup->failed()) {
            throw CloudApiException::fromResponse($lookup);
        }

        $url = $lookup->json('url');
        $mimeType = (string) ($lookup->json('mime_type') ?? 'application/octet-stream');
        $declaredSize = (int) ($lookup->json('file_size') ?? 0);

        if (! is_string($url) || $url === '') {
            throw new CloudApiException('The WhatsApp Cloud API answered the media lookup without a url.');
        }

        if ($allowedMimeTypes !== [] && ! in_array($mimeType, $allowedMimeTypes, true)) {
            throw MediaDownloadException::typeNotAllowed($mimeType);
        }

        if ($declaredSize > $maxBytes) {
            throw MediaDownloadException::tooLarge($declaredSize, $maxBytes);
        }

        try {
            $file = Http::withToken($credentials->accessToken())->timeout(30)->get($url);
        } catch (ConnectionException $exception) {
            throw new CloudApiException('Could not download the media from the WhatsApp Cloud API.', previous: $exception);
        }

        if ($file->failed()) {
            throw CloudApiException::fromResponse($file);
        }

        $media = new DownloadedMedia($file->body(), $mimeType);

        if ($media->size > $maxBytes) {
            throw MediaDownloadException::tooLarge($media->size, $maxBytes);
        }

        return $media;
    }
}

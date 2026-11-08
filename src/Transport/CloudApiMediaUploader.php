<?php

namespace Callcocam\WhatsAppCloud\Transport;

use Callcocam\WhatsAppCloud\Contracts\MediaUploader;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The real upload: a multipart POST to the number's `/media` endpoint.
 */
class CloudApiMediaUploader implements MediaUploader
{
    public function upload(WhatsAppCredentials $credentials, string $contents, string $mimeType, string $filename): string
    {
        $graphVersion = $credentials->graphVersion() ?? (string) config('whatsapp-cloud.graph_version', 'v21.0');
        $url = "https://graph.facebook.com/{$graphVersion}/{$credentials->phoneNumberId()}/media";

        try {
            $response = Http::withToken($credentials->accessToken())
                ->acceptJson()
                ->timeout(30)
                ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
                ->post($url, ['messaging_product' => 'whatsapp', 'type' => $mimeType]);
        } catch (ConnectionException $exception) {
            throw new CloudApiException('Could not connect to the WhatsApp Cloud API.', previous: $exception);
        }

        if ($response->failed()) {
            throw CloudApiException::fromResponse($response);
        }

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new CloudApiException('The WhatsApp Cloud API answered the media upload without an id.');
        }

        return $id;
    }
}

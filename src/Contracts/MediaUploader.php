<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;

/**
 * Hands a file to Meta and gets back the media id a message refers to (a template's
 * DOCUMENT/IMAGE header, for one). The file never needs a public URL: Meta keeps
 * it for 30 days under that id, reachable only by the WABA that uploaded it.
 *
 * Chosen by the same `whatsapp-cloud.driver` as {@see MessageTransport}, resolved
 * lazily by {@see CloudApiClient::uploadMedia()}: under the sandbox nothing
 * leaves the machine.
 */
interface MediaUploader
{
    /**
     * @return string the media id (`{"id": "…"}` of the `/media` endpoint)
     *
     * @throws CloudApiException on any upload failure, carrying Meta's error code
     */
    public function upload(WhatsAppCredentials $credentials, string $contents, string $mimeType, string $filename): string;
}

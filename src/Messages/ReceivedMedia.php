<?php

namespace Callcocam\WhatsAppCloud\Messages;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Contracts\InboundAudioHandler;
use Callcocam\WhatsAppCloud\Contracts\InboundFileHandler;
use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\MediaDownloadException;
use Callcocam\WhatsAppCloud\Facades\WhatsApp;

/**
 * A photo, document or audio someone sent, as an {@see InboundAudioHandler} or
 * {@see InboundFileHandler} gets it: what it is, who sent it, and how to fetch it.
 *
 * The bytes are NOT downloaded up front. The handler decides whether it wants the
 * file at all, and with which limits — the default handlers do, an app's own may
 * only need the caption.
 */
final class ReceivedMedia
{
    public function __construct(
        public readonly InboundMedia $media,
        public readonly string $from,
        public readonly string $wamid,
        public readonly ?string $contactName = null,
        public readonly ?string $phoneNumberId = null,
    ) {}

    /**
     * Read the media part of an inbound message, or null when it carries none the
     * package reads ({@see InboundMedia::TYPES}).
     */
    public static function fromEvent(WhatsAppMessageReceived $event): ?self
    {
        $media = InboundMedia::fromMessage($event->message);
        $from = $event->message['from'] ?? null;
        $wamid = $event->message['id'] ?? null;

        if ($media === null || ! is_string($from) || $from === '' || ! is_string($wamid) || $wamid === '') {
            return null;
        }

        return new self($media, $from, $wamid, self::contactName($event, $from), $event->phoneNumberId);
    }

    /**
     * Fetch the bytes. `$maxBytes` defaults to `whatsapp-cloud.media.max_download_bytes`.
     *
     * @param  list<string>  $allowedMimeTypes  empty accepts any type
     *
     * @throws MediaDownloadException when the file is too large or of a type not allowed
     * @throws CloudApiException on any Meta or network failure
     */
    public function download(?int $maxBytes = null, array $allowedMimeTypes = []): DownloadedMedia
    {
        return $this->client()->downloadMedia($this->media->id, $maxBytes, $allowedMimeTypes);
    }

    /**
     * Answer the sender with free text. The message they just sent opened the 24h
     * window, so this is allowed.
     */
    public function reply(string $text): SendResult
    {
        return $this->client()->sendSessionText($this->from, $text);
    }

    private function client(): CloudApiClient
    {
        return WhatsApp::for();
    }

    private static function contactName(WhatsAppMessageReceived $event, string $from): ?string
    {
        foreach ((array) ($event->value['contacts'] ?? []) as $contact) {
            if (($contact['wa_id'] ?? null) === $from) {
                $name = $contact['profile']['name'] ?? null;

                return is_string($name) && $name !== '' ? $name : null;
            }
        }

        return null;
    }
}

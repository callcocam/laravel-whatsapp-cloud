<?php

namespace Callcocam\WhatsAppCloud\Listeners;

use Callcocam\WhatsAppCloud\Contracts\InboundAudioHandler;
use Callcocam\WhatsAppCloud\Contracts\InboundFileHandler;
use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Messages\InboundMedia;
use Callcocam\WhatsAppCloud\Messages\ReceivedMedia;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Hands a received photo, document or audio to the app's handler
 * ({@see InboundFileHandler}, {@see InboundAudioHandler}) — only for the kinds
 * turned on in `whatsapp-cloud.media.handle`, all off by default.
 *
 * Queued: downloading, transcribing and answering take seconds, and Meta wants
 * the webhook answered at once. With nothing to do for the message it is not
 * queued at all ({@see self::shouldQueue()}). One try: a second run would answer
 * the person twice.
 */
class HandleInboundMedia implements ShouldQueue
{
    public int $tries = 1;

    public function shouldQueue(WhatsAppMessageReceived $event): bool
    {
        $media = InboundMedia::fromMessage($event->message);

        return $media !== null && self::enabled($media->type);
    }

    public function viaConnection(): ?string
    {
        return config('whatsapp-cloud.media.queue_connection');
    }

    public function viaQueue(): ?string
    {
        return config('whatsapp-cloud.media.queue');
    }

    public function handle(WhatsAppMessageReceived $event): void
    {
        $received = ReceivedMedia::fromEvent($event);

        if ($received === null || ! self::enabled($received->media->type)) {
            return;
        }

        $received->media->isAudio()
            ? app(InboundAudioHandler::class)->handle($received)
            : app(InboundFileHandler::class)->handle($received);
    }

    /**
     * Whether the app asked the package to handle this kind (`image`, `document`,
     * `audio`).
     */
    public static function enabled(string $type): bool
    {
        return (bool) config("whatsapp-cloud.media.handle.{$type}", false);
    }
}

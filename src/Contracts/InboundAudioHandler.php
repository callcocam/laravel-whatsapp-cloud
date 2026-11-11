<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\Media\DefaultAudioHandler;
use Callcocam\WhatsAppCloud\Messages\ReceivedMedia;

/**
 * What the app does with an audio someone sent (a voice note or an audio file).
 * Called only when `whatsapp-cloud.media.handle.audio` is on.
 *
 * The package binds {@see DefaultAudioHandler}, which only answers that the audio
 * arrived (and what it said, when an {@see AudioTranscriber} is configured). An
 * app binds its own to put the audio to use:
 *
 *     $this->app->bind(InboundAudioHandler::class, MyAudioHandler::class);
 */
interface InboundAudioHandler
{
    public function handle(ReceivedMedia $audio): void;
}

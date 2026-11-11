<?php

namespace Callcocam\WhatsAppCloud\Media;

use Callcocam\WhatsAppCloud\Contracts\AudioTranscriber;
use Callcocam\WhatsAppCloud\Contracts\InboundAudioHandler;
use Callcocam\WhatsAppCloud\Messages\ReceivedMedia;

/**
 * Answers that the audio arrived and, when a transcriber is configured, what it
 * said. Nothing is kept: an app binds its own {@see InboundAudioHandler} to act on
 * the audio.
 */
class DefaultAudioHandler implements InboundAudioHandler
{
    use RepliesByDefault;

    public function __construct(private readonly AudioTranscriber $transcriber) {}

    public function handle(ReceivedMedia $audio): void
    {
        $file = $this->downloadOrExplain($audio);

        if ($file === null) {
            return;
        }

        $text = $this->transcriber->transcribe($file);

        $this->answer(
            $audio,
            $text === null ? $this->line('audio') : $this->line('audio_transcribed', [':text' => $text]),
            InboundAudioHandler::class,
        );
    }
}

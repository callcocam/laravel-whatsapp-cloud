<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\Media\NullAudioTranscriber;
use Callcocam\WhatsAppCloud\Media\OpenAiAudioTranscriber;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;

/**
 * Turns an audio into text. Chosen by `whatsapp-cloud.media.transcriber`:
 * none ({@see NullAudioTranscriber}) or `openai` ({@see OpenAiAudioTranscriber});
 * an app binds its own for any other provider.
 */
interface AudioTranscriber
{
    /**
     * @return string|null the text, or null when nothing could be transcribed
     *                     (no transcriber configured, silence, a provider failure)
     */
    public function transcribe(DownloadedMedia $audio): ?string;
}

<?php

namespace Callcocam\WhatsAppCloud\Media;

use Callcocam\WhatsAppCloud\Contracts\AudioTranscriber;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;

/**
 * No transcriber configured: the audio is acknowledged, never read.
 */
class NullAudioTranscriber implements AudioTranscriber
{
    public function transcribe(DownloadedMedia $audio): ?string
    {
        return null;
    }
}

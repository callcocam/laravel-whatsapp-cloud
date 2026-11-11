<?php

namespace Callcocam\WhatsAppCloud\Media;

use Callcocam\WhatsAppCloud\Contracts\AudioTranscriber;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcribes with OpenAI's `/v1/audio/transcriptions` (`whisper-1` by default).
 * WhatsApp voice notes are Ogg/Opus, which it reads as is.
 *
 * Never throws: a failed transcription is logged and answered as null, so the
 * person still hears back that the audio arrived.
 */
class OpenAiAudioTranscriber implements AudioTranscriber
{
    /**
     * The extension OpenAI reads the format from, by mime type (parameters such as
     * `; codecs=opus` dropped).
     */
    private const EXTENSIONS = [
        'audio/ogg' => 'ogg',
        'audio/opus' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/flac' => 'flac',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'whisper-1',
        private readonly ?string $language = null,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
    ) {}

    public function transcribe(DownloadedMedia $audio): ?string
    {
        $mime = strtolower(trim(explode(';', $audio->mimeType)[0]));
        $filename = 'audio.'.(self::EXTENSIONS[$mime] ?? 'ogg');

        $fields = array_filter([
            'model' => $this->model,
            'language' => $this->language,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->attach('file', $audio->contents, $filename)
                ->post(rtrim($this->baseUrl, '/').'/audio/transcriptions', $fields);
        } catch (Throwable $exception) {
            Log::warning('whatsapp cloud: audio transcription failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            Log::warning('whatsapp cloud: audio transcription failed', [
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return null;
        }

        $text = trim((string) $response->json('text'));

        return $text === '' ? null : $text;
    }
}

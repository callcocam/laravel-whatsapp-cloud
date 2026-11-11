<?php

namespace Callcocam\WhatsAppCloud\Media;

use Callcocam\WhatsAppCloud\Contracts\AudioTranscriber;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcribes with Cloudflare Workers AI (`@cf/openai/whisper-large-v3-turbo` by
 * default), through its `/ai/run/{model}` endpoint: the audio goes base64 in
 * JSON, the text comes back in `result.text`.
 *
 * Never throws: a failed transcription is logged and answered as null, so the
 * person still hears back that the audio arrived.
 */
class CloudflareAudioTranscriber implements AudioTranscriber
{
    public function __construct(
        private readonly string $accountId,
        private readonly string $token,
        private readonly string $model = '@cf/openai/whisper-large-v3-turbo',
        private readonly ?string $language = null,
        private readonly string $baseUrl = 'https://api.cloudflare.com/client/v4',
    ) {}

    public function transcribe(DownloadedMedia $audio): ?string
    {
        $url = rtrim($this->baseUrl, '/')."/accounts/{$this->accountId}/ai/run/{$this->model}";

        $payload = array_filter([
            'audio' => base64_encode($audio->contents),
            'language' => $this->language,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = Http::withToken($this->token)->timeout(60)->post($url, $payload);
        } catch (Throwable $exception) {
            Log::warning('whatsapp cloud: audio transcription failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed() || $response->json('success') === false) {
            Log::warning('whatsapp cloud: audio transcription failed', [
                'status' => $response->status(),
                'error' => $response->json('errors.0.message'),
            ]);

            return null;
        }

        $text = trim((string) $response->json('result.text'));

        return $text === '' ? null : $text;
    }
}

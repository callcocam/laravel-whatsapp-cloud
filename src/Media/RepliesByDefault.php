<?php

namespace Callcocam\WhatsAppCloud\Media;

use Callcocam\WhatsAppCloud\Exceptions\MediaDownloadException;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;
use Callcocam\WhatsAppCloud\Messages\ReceivedMedia;
use Illuminate\Support\Facades\Log;

/**
 * What the default handlers share: download (answering a refusal instead of
 * failing), the texts of `whatsapp-cloud.media.replies`, and the nudge — in the
 * reply outside production, in the log always — that the app has not taken over.
 */
trait RepliesByDefault
{
    /**
     * The bytes, or null after answering why they could not be had.
     */
    protected function downloadOrExplain(ReceivedMedia $media): ?DownloadedMedia
    {
        try {
            return $media->download();
        } catch (MediaDownloadException $exception) {
            if ($exception->reason === MediaDownloadException::TOO_LARGE) {
                $this->send($media, $this->line('too_large', [
                    ':max' => self::humanSize((int) config('whatsapp-cloud.media.max_download_bytes', 16 * 1024 * 1024)),
                ]));
            }

            return null;
        }
    }

    /**
     * Send the reply, with the hint appended when it is on, and log that the
     * default handler answered.
     *
     * @param  class-string  $contract
     */
    protected function answer(ReceivedMedia $media, string $text, string $contract): void
    {
        Log::info('whatsapp cloud: the package\'s default handler answered a received '.$media->media->type.'. Bind '.$contract.' to put it to use in the app.', [
            'wamid' => $media->wamid,
        ]);

        if ($this->hintEnabled()) {
            $text .= "\n\n".$this->line('hint', [':contract' => class_basename($contract)]);
        }

        $this->send($media, $text);
    }

    protected function send(ReceivedMedia $media, string $text): void
    {
        $media->reply($text);
    }

    /**
     * @param  array<string, string>  $replace
     */
    protected function line(string $key, array $replace = []): string
    {
        return strtr((string) config("whatsapp-cloud.media.replies.{$key}", ''), $replace);
    }

    protected function withCaption(string $text, ReceivedMedia $media): string
    {
        $caption = trim((string) $media->media->caption);

        return $caption === '' ? $text : $text."\n".$this->line('caption', [':caption' => $caption]);
    }

    protected function hintEnabled(): bool
    {
        $hint = config('whatsapp-cloud.media.hint');

        return $hint === null ? ! app()->isProduction() : (bool) $hint;
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 0, ',', '.').' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
    }
}

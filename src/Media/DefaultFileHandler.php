<?php

namespace Callcocam\WhatsAppCloud\Media;

use Callcocam\WhatsAppCloud\Contracts\InboundFileHandler;
use Callcocam\WhatsAppCloud\Messages\InboundMedia;
use Callcocam\WhatsAppCloud\Messages\ReceivedMedia;

/**
 * Answers what arrived — the document's name, what kind of file it is, its size,
 * the caption — so the person knows it got through. Nothing is kept: an app binds
 * its own {@see InboundFileHandler} to act on the file.
 */
class DefaultFileHandler implements InboundFileHandler
{
    use RepliesByDefault;

    /**
     * How a mime type reads to the person (parameters dropped). Anything else is
     * named by its subtype in capitals.
     */
    private const KINDS = [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'imagem JPEG',
        'image/png' => 'imagem PNG',
        'image/webp' => 'imagem WebP',
        'text/plain' => 'texto',
        'text/csv' => 'planilha CSV',
        'application/vnd.ms-excel' => 'planilha Excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'planilha Excel',
        'application/msword' => 'documento Word',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'documento Word',
        'application/vnd.ms-powerpoint' => 'apresentação PowerPoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'apresentação PowerPoint',
        'application/zip' => 'arquivo compactado ZIP',
        'application/xml' => 'XML',
        'text/xml' => 'XML',
        'application/x-ofx' => 'extrato OFX',
    ];

    public function handle(ReceivedMedia $file): void
    {
        $downloaded = $this->downloadOrExplain($file);

        if ($downloaded === null) {
            return;
        }

        $size = self::humanSize($downloaded->size);

        $text = $file->media->type === InboundMedia::IMAGE
            ? $this->line('image', [':size' => $size])
            : $this->line('document', [
                ':name' => $file->media->filename ?? 'sem nome',
                ':kind' => self::kind($downloaded->mimeType, $file->media->filename),
                ':size' => $size,
            ]);

        $this->answer($file, $this->withCaption($text, $file), InboundFileHandler::class);
    }

    public static function kind(string $mimeType, ?string $filename = null): string
    {
        $mime = strtolower(trim(explode(';', $mimeType)[0]));

        if (isset(self::KINDS[$mime])) {
            return self::KINDS[$mime];
        }

        $extension = $filename !== null ? pathinfo($filename, PATHINFO_EXTENSION) : '';

        if ($extension !== '') {
            return 'arquivo '.strtoupper($extension);
        }

        return 'arquivo '.strtoupper((string) (explode('/', $mime)[1] ?? $mime));
    }
}

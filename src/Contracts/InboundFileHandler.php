<?php

namespace Callcocam\WhatsAppCloud\Contracts;

use Callcocam\WhatsAppCloud\Media\DefaultFileHandler;
use Callcocam\WhatsAppCloud\Messages\ReceivedMedia;

/**
 * What the app does with a photo or a document someone sent. Called only for the
 * kinds turned on in `whatsapp-cloud.media.handle` (`image`, `document`).
 *
 * The package binds {@see DefaultFileHandler}, which only answers what arrived
 * (name, kind, size). An app binds its own to put the file to use:
 *
 *     $this->app->bind(InboundFileHandler::class, MyFileHandler::class);
 */
interface InboundFileHandler
{
    public function handle(ReceivedMedia $file): void;
}

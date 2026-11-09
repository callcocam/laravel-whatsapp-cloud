<?php

namespace Callcocam\WhatsAppCloud\Exceptions;

/**
 * The file exists but the app said it does not want it: too large or of a type
 * not allowed. Terminal: downloading again gives the same file.
 */
class MediaDownloadException extends WhatsAppException
{
    public const TOO_LARGE = 'too_large';

    public const TYPE_NOT_ALLOWED = 'type_not_allowed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function tooLarge(int $bytes, int $maxBytes): self
    {
        return new self(self::TOO_LARGE, "The media has {$bytes} bytes, more than the {$maxBytes} allowed.");
    }

    public static function typeNotAllowed(string $mimeType): self
    {
        return new self(self::TYPE_NOT_ALLOWED, "The media type [{$mimeType}] is not allowed.");
    }

    public function isTerminal(): bool
    {
        return true;
    }
}

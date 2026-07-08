<?php

namespace Callcocam\WhatsAppCloud\Exceptions;

use RuntimeException;

/**
 * Base for every WhatsApp send/connection error. Callers catch this type;
 * {@see CloudApiException} subclasses it and refines
 * {@see isTemporaryRestriction()} for Meta's error codes (re-engagement /
 * unavailable, template problems, etc.).
 */
class WhatsAppException extends RuntimeException
{
    /**
     * Whether this is a terminal restriction the caller should log and skip
     * instead of retrying (a block on starting conversations, not a transient
     * failure). The base is never terminal; the Cloud subclass decides per code.
     */
    public function isTemporaryRestriction(): bool
    {
        return false;
    }
}

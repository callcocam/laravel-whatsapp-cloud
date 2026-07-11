<?php

namespace Callcocam\WhatsAppCloud\Exceptions;

use RuntimeException;

/**
 * Base for every WhatsApp send/connection error. Callers catch this type;
 * {@see CloudApiException} subclasses it and refines {@see isTerminal()} for
 * Meta's error codes (re-engagement / unavailable, template problems, etc.).
 */
class WhatsAppException extends RuntimeException
{
    /**
     * Whether retrying is pointless: the caller should log and skip instead of
     * letting the queue retry (a block on starting conversations, not a
     * transient failure). The base is never terminal; the Cloud subclass decides
     * per error code.
     */
    public function isTerminal(): bool
    {
        return false;
    }

    /**
     * @deprecated Use {@see isTerminal()}. The old name says the opposite of what
     *             it does — it returns TRUE for errors that must NOT be retried.
     *             Kept as an alias so existing callers keep working.
     */
    public function isTemporaryRestriction(): bool
    {
        return $this->isTerminal();
    }
}

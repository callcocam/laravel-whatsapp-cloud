<?php

namespace Callcocam\WhatsAppCloud\Exceptions;

/**
 * The sandbox itself is misconfigured — as opposed to a simulated Meta failure,
 * which is a {@see CloudApiException} and is the sandbox working as intended.
 *
 * Always terminal: no amount of retrying fixes a missing app secret.
 */
class SandboxException extends WhatsAppException
{
    public function isTerminal(): bool
    {
        return true;
    }
}

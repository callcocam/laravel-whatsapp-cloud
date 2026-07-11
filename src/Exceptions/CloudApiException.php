<?php

namespace Callcocam\WhatsAppCloud\Exceptions;

use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Meta Cloud API error. Extends the shared {@see WhatsAppException} base so
 * callers treat "don't retry" cases uniformly. The Graph API returns a numeric
 * `error.code` we map to retry-vs-terminal.
 */
class CloudApiException extends WhatsAppException
{
    public function __construct(string $message, public readonly ?int $errorCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Build from a failed Graph API response, extracting Meta's error envelope.
     */
    public static function fromResponse(Response $response): self
    {
        /** @var array<string, mixed> $error */
        $error = (array) ($response->json('error') ?? []);

        $message = is_string($error['message'] ?? null) ? $error['message'] : $response->reason();
        $code = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null;

        return new self('WhatsApp Cloud API error: '.$message, $code);
    }

    /**
     * Meta error codes where retrying now won't help. Covers the closed 24h
     * window / re-engagement, undeliverable recipients, account policy
     * restrictions and template problems. Everything NOT listed (rate limits,
     * transient network) stays retryable, so the queue's backoff can pick it up.
     *
     * @var list<int>
     */
    private const TERMINAL_CODES = [
        131047, // re-engagement message (24h session window closed)
        131026, // message undeliverable (recipient cannot receive)
        131051, // unsupported message type
        131048, // spam rate limit hit (account restricted)
        131031, // account locked
        368,    // temporarily blocked for policy violations
        132000, // template param count mismatch
        132001, // template does not exist
        132005, // template translated content mismatch
        132007, // template format character policy violation
        132012, // template param format mismatch
        132015, // template is paused
        132016, // template is disabled
    ];

    /**
     * Whether this is a terminal Meta error the caller should log and skip
     * instead of letting the queue retry it.
     */
    public function isTerminal(): bool
    {
        return $this->errorCode !== null && in_array($this->errorCode, self::TERMINAL_CODES, true);
    }
}

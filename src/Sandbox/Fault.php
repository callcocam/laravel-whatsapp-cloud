<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;

/**
 * One failure the sandbox can inject, described exactly as Meta describes it.
 *
 * The `title`/`message`/`details` strings are Meta's own English wording, not
 * ours: they end up verbatim in the simulated `statuses[].errors[]` payload, and
 * an app that pattern-matches on them in production must see the same text here.
 */
final class Fault
{
    public function __construct(
        public readonly string $key,
        public readonly ?int $code,
        public readonly string $title,
        public readonly string $message,
        public readonly string $details,
        /** Simulate a dead network rather than an API error (no Meta error code). */
        public readonly bool $connection = false,
    ) {}

    /**
     * Whether the app should give up rather than let the queue retry. Delegates
     * to the real exception, so the sandbox can never disagree with production
     * about what "terminal" means.
     */
    public function isTerminal(): bool
    {
        return (new CloudApiException($this->message, $this->code))->isTerminal();
    }

    /**
     * @return array<string, mixed> a `statuses[].errors[]` entry
     */
    public function toWebhookError(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'message' => $this->message,
            'error_data' => ['details' => $this->details],
        ];
    }
}

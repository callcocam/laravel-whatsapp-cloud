<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Throwable;

/**
 * What actually happened when a simulated webhook was delivered — the record the
 * inspector shows.
 *
 * `failure` is the whole reason this type exists. When a listener of the host app
 * throws, we want the developer to SEE the exception, not a 500. Production hides
 * it behind the exception handler; the sandbox must not.
 */
final class SimulatedWebhook
{
    /**
     * @param  array<string, mixed>  $payload  the webhook body, decoded
     * @param  list<string>  $listeners  the listeners registered for the events this fired
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $body,
        public readonly string $signature,
        public readonly ?int $status,
        public readonly array $listeners = [],
        public readonly ?Throwable $failure = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->failure === null && $this->status === 200;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payload' => $this->payload,
            'signature' => $this->signature,
            'status' => $this->status,
            'listeners' => $this->listeners,
            'failure' => $this->failure === null ? null : [
                'class' => $this->failure::class,
                'message' => $this->failure->getMessage(),
                'file' => $this->failure->getFile().':'.$this->failure->getLine(),
            ],
        ];
    }
}

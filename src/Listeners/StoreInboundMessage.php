<?php

namespace Callcocam\WhatsAppCloud\Listeners;

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Models\WhatsAppInboundMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists every inbound message to `whatsapp_inbound_messages`, so a host app
 * has a durable log of everything that arrived — the package otherwise stores
 * nothing. Wired up by the service provider only when
 * `whatsapp-cloud.inbound.store` is on.
 *
 * Runs SYNCHRONOUSLY inside the webhook request (not queued) on purpose: the
 * message is saved before we answer Meta, so it survives a stopped queue. It
 * also never throws — a storage failure (e.g. the migration was not run) is
 * logged, never allowed to break the webhook or the other listeners.
 */
class StoreInboundMessage
{
    public function handle(WhatsAppMessageReceived $event): void
    {
        /** @var class-string<WhatsAppInboundMessage> $model */
        $model = config('whatsapp-cloud.inbound.model', WhatsAppInboundMessage::class);

        $message = $event->message;

        $wamid = $message['id'] ?? null;

        if (! is_string($wamid) || $wamid === '') {
            return;
        }

        try {
            $model::query()->updateOrCreate(
                ['wamid' => $wamid],
                [
                    'phone_number_id' => $event->phoneNumberId,
                    'wa_id' => (string) ($message['from'] ?? ''),
                    'contact_name' => $this->contactName($event),
                    'type' => (string) ($message['type'] ?? 'unknown'),
                    'text' => $this->text($message),
                    'context_id' => $message['context']['id'] ?? null,
                    'payload' => $message,
                ],
            );
        } catch (Throwable $exception) {
            // Never break the webhook over storage — the message still reaches
            // every other listener. Most likely the migration was not run.
            Log::warning('whatsapp cloud: failed to store inbound message', [
                'wamid' => $wamid,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The sender's WhatsApp profile name, matched to the message's `from` in the
     * change's `contacts`. Null when Meta did not include it.
     */
    protected function contactName(WhatsAppMessageReceived $event): ?string
    {
        $from = $event->message['from'] ?? null;

        foreach ((array) ($event->value['contacts'] ?? []) as $contact) {
            if (($contact['wa_id'] ?? null) === $from) {
                $name = $contact['profile']['name'] ?? null;

                return is_string($name) && $name !== '' ? $name : null;
            }
        }

        return null;
    }

    /**
     * The plain text body, for text messages only.
     *
     * @param  array<string, mixed>  $message
     */
    protected function text(array $message): ?string
    {
        if (($message['type'] ?? null) !== 'text') {
            return null;
        }

        $body = trim((string) ($message['text']['body'] ?? ''));

        return $body === '' ? null : $body;
    }
}

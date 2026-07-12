<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Illuminate\Support\Str;

/**
 * Builds the webhook payloads Meta would POST to us. This is the load-bearing
 * class of the whole sandbox: if these shapes drift from Meta's, every test run
 * against them is a lie.
 *
 * One instance is bound to one conversation — a business number talking to one
 * contact — because every payload carries both identities.
 *
 * Three shapes people routinely conflate, and that an app must handle
 * separately:
 *   - tapping a button on a TEMPLATE      → `type: button`      (button.text/payload)
 *   - tapping a button on an INTERACTIVE  → `type: interactive` (interactive.button_reply)
 *   - picking a row on an interactive LIST → `type: interactive` (interactive.list_reply)
 */
final class InboundPayloadFactory
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $displayPhoneNumber,
        private readonly string $waId,
        private readonly string $profileName,
        private readonly ?string $wabaId = null,
    ) {}

    public static function for(
        WhatsAppCredentials $credentials,
        string $waId,
        string $profileName,
        ?string $displayPhoneNumber = null,
    ): self {
        return new self(
            phoneNumberId: $credentials->phoneNumberId(),
            displayPhoneNumber: $displayPhoneNumber
                ?? (string) config('whatsapp-cloud.sandbox.display_phone_number', '15550000000'),
            waId: $waId,
            profileName: $profileName,
            wabaId: $credentials->wabaId(),
        );
    }

    /**
     * A free-text reply.
     *
     * @return array<string, mixed>
     */
    public function text(string $body, ?string $replyTo = null): array
    {
        return $this->message([
            'type' => 'text',
            'text' => ['body' => $body],
        ], $replyTo);
    }

    /**
     * A tap on a TEMPLATE quick-reply button.
     *
     * Note `payload` === `text`. Meta only lets you choose the payload when the
     * SEND specified `components[{type: button, sub_type: quick_reply,
     * parameters: [{type: payload}]}]` — which this package never does. Making
     * the sandbox return a distinct payload would let an app be written against
     * a message production never sends.
     *
     * @return array<string, mixed>
     */
    public function templateButton(string $text, ?string $replyTo = null): array
    {
        return $this->message([
            'type' => 'button',
            'button' => ['payload' => $text, 'text' => $text],
        ], $replyTo);
    }

    /**
     * A tap on an INTERACTIVE reply button.
     *
     * @return array<string, mixed>
     */
    public function buttonReply(string $id, string $title, ?string $replyTo = null): array
    {
        return $this->message([
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button_reply',
                'button_reply' => ['id' => $id, 'title' => $title],
            ],
        ], $replyTo);
    }

    /**
     * A row picked from an interactive LIST — what sendInteractive() produces.
     *
     * @return array<string, mixed>
     */
    public function listReply(string $id, string $title, ?string $description = null, ?string $replyTo = null): array
    {
        $reply = ['id' => $id, 'title' => $title];

        if ($description !== null) {
            $reply['description'] = $description;
        }

        return $this->message([
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list_reply',
                'list_reply' => $reply,
            ],
        ], $replyTo);
    }

    /**
     * An inbound image. Media is referenced by id — the sandbox never uploads
     * anything, and an app that blindly downloads media will notice here rather
     * than in production.
     *
     * @return array<string, mixed>
     */
    public function image(?string $caption = null, ?string $replyTo = null): array
    {
        $image = [
            'mime_type' => 'image/jpeg',
            'sha256' => hash('sha256', 'sandbox-image'),
            'id' => 'media.SANDBOX.'.Str::ulid(),
        ];

        if ($caption !== null) {
            $image['caption'] = $caption;
        }

        return $this->message(['type' => 'image', 'image' => $image], $replyTo);
    }

    /**
     * A delivery status for a message WE sent. `failed` carries the errors[]
     * array — the shape an app must read to learn why.
     *
     * @return array<string, mixed>
     */
    public function status(string $wamid, string $status, ?Fault $fault = null): array
    {
        $entry = [
            'id' => $wamid,
            'status' => $status,
            'timestamp' => $this->timestamp(),
            'recipient_id' => $this->waId,
        ];

        if ($status === 'failed' && $fault instanceof Fault) {
            $entry['errors'] = [$fault->toWebhookError()];
        }

        if ($status !== 'failed') {
            $entry['conversation'] = [
                'id' => 'conv.SANDBOX.'.Str::ulid(),
                'origin' => ['type' => 'utility'],
            ];
            $entry['pricing'] = [
                'billable' => true,
                'pricing_model' => 'CBP',
                'category' => 'utility',
            ];
        }

        return $this->wrap(['statuses' => [$entry]]);
    }

    /**
     * @param  array<string, mixed>  $body  the type-specific part of the message
     * @return array<string, mixed>
     */
    private function message(array $body, ?string $replyTo): array
    {
        $message = [
            'from' => $this->waId,
            'id' => self::wamid(),
            'timestamp' => $this->timestamp(),
        ];

        // Meta sets `context` whenever the user is answering a specific message.
        // Without it, an app cannot correlate a button tap with what it sent —
        // which is the whole point of a handoff flow.
        if ($replyTo !== null) {
            $message['context'] = ['from' => $this->displayPhoneNumber, 'id' => $replyTo];
        }

        return $this->wrap([
            'contacts' => [[
                'profile' => ['name' => $this->profileName],
                'wa_id' => $this->waId,
            ]],
            'messages' => [array_merge($message, $body)],
        ]);
    }

    /**
     * The `entry[].changes[]` envelope every webhook shares.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function wrap(array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $this->wabaId ?? '0',
                'changes' => [[
                    'field' => 'messages',
                    'value' => array_merge([
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => $this->displayPhoneNumber,
                            // The REAL phone number id. The app resolves its tenant
                            // from this — a made-up one would strand every listener.
                            'phone_number_id' => $this->phoneNumberId,
                        ],
                    ], $value),
                ]],
            ]],
        ];
    }

    /**
     * Greppable on purpose: if a sandbox id ever leaks into a production table,
     * you want to be able to find every one of them.
     */
    public static function wamid(): string
    {
        return 'wamid.SANDBOX.'.Str::ulid();
    }

    private function timestamp(): string
    {
        return (string) now()->timestamp;
    }
}

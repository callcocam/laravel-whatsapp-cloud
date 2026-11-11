<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxConversation;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxMessage;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Support\Str;

/**
 * The sandbox, as a thing you can drive: from the screen, from a test, from
 * tinker.
 *
 * The outbound half needs nothing here — the app already sends through
 * {@see WhatsApp}, and {@see SandboxTransport}
 * quietly catches it. This class is the half that has no natural caller: the
 * person on the other end of the conversation.
 */
class Sandbox
{
    /**
     * What WhatsApp sends as a photo. Everything else goes as a document.
     */
    public const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png'];

    public function __construct(
        private readonly WhatsAppManager $whatsapp,
        private readonly WebhookSimulator $simulator,
    ) {}

    public function participant(string $waId, string $name, string $role = 'customer'): SandboxConversation
    {
        $conversation = SandboxConversation::firstOrCreate(
            [
                'phone_number_id' => $this->whatsapp->credentials()->phoneNumberId(),
                'wa_id' => $waId,
            ],
            ['name' => $name, 'role' => $role],
        );

        // A number first met as an outbound recipient was created with the number
        // as its name. Once a human names it, keep the name.
        if ($conversation->name !== $name || $conversation->role !== $role) {
            $conversation->forceFill(['name' => $name, 'role' => $role])->save();
        }

        return $conversation;
    }

    /**
     * The person types something back.
     */
    public function reply(SandboxConversation $conversation, string $text, ?string $replyTo = null): SimulatedWebhook
    {
        return $this->deliver(
            $conversation,
            $this->factory($conversation)->text($text, $replyTo),
            type: 'text',
            rendered: $text,
        );
    }

    /**
     * The person sends a file: a photo (JPEG or PNG) comes back as `type: image`,
     * an audio as `type: audio` (a voice note when `$voice`), anything else as
     * `type: document`, exactly as Meta splits them. The bytes are put under the
     * media id ({@see SandboxMediaDownloader::put()}), so when the app downloads
     * what it received, it gets THIS file. Audio carries no caption on WhatsApp.
     */
    public function sendMedia(
        SandboxConversation $conversation,
        string $contents,
        string $mimeType,
        string $filename,
        ?string $caption = null,
        ?string $replyTo = null,
        bool $voice = false,
    ): SimulatedWebhook {
        $type = match (true) {
            in_array($mimeType, self::IMAGE_MIME_TYPES, true) => 'image',
            $voice || str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'document',
        };

        $mediaId = match ($type) {
            'image' => 'media.SANDBOX.',
            'audio' => InboundPayloadFactory::AUDIO_MEDIA_PREFIX,
            default => InboundPayloadFactory::DOCUMENT_MEDIA_PREFIX,
        }.Str::ulid();

        SandboxMediaDownloader::put($mediaId, $contents, $mimeType);

        $factory = $this->factory($conversation);

        return $this->deliver(
            $conversation,
            match ($type) {
                'image' => $factory->image($caption, $replyTo, $mediaId, $mimeType),
                'audio' => $factory->audio($replyTo, $mediaId, $mimeType, $voice),
                default => $factory->document($filename, $mimeType, $caption, $replyTo, $mediaId),
            },
            type: $type,
            rendered: $type === 'audio' ? '' : ($caption ?? ''),
        );
    }

    /**
     * The person taps a quick-reply button on a TEMPLATE. Comes back to the app as
     * `type: button` — not as an interactive reply.
     */
    public function tapTemplateButton(SandboxConversation $conversation, string $text, string $replyTo): SimulatedWebhook
    {
        return $this->deliver(
            $conversation,
            $this->factory($conversation)->templateButton($text, $replyTo, $this->buttonPayload($conversation, $replyTo, $text)),
            type: 'button',
            rendered: $text,
        );
    }

    /**
     * The person taps a reply button on an INTERACTIVE message. Comes back as
     * `interactive.button_reply` — a different shape from a template button, and
     * the one an app gets whenever it sends buttons itself instead of a template.
     */
    public function tapReplyButton(
        SandboxConversation $conversation,
        string $id,
        string $title,
        string $replyTo,
    ): SimulatedWebhook {
        return $this->deliver(
            $conversation,
            $this->factory($conversation)->buttonReply($id, $title, $replyTo),
            type: 'interactive',
            rendered: $title,
        );
    }

    /**
     * The person picks a row from an interactive list — what sendInteractive()
     * provokes.
     */
    public function pickListRow(
        SandboxConversation $conversation,
        string $id,
        string $title,
        string $replyTo,
    ): SimulatedWebhook {
        return $this->deliver(
            $conversation,
            $this->factory($conversation)->listReply($id, $title, $this->rowDescription($conversation, $replyTo, $id), $replyTo),
            type: 'interactive',
            rendered: $title,
        );
    }

    /**
     * The payload the SEND gave to the tapped quick-reply button: the button's
     * position among the template's quick replies, matched to the `button`
     * component of that index. Null when the send set none (Meta echoes the text).
     */
    protected function buttonPayload(SandboxConversation $conversation, string $replyTo, string $text): ?string
    {
        $message = $conversation->messages()->where('wamid', $replyTo)->first();

        $quickReplies = array_values(array_filter(
            (array) data_get(collect((array) $message?->template_components)->firstWhere('type', 'BUTTONS'), 'buttons', []),
            static fn ($button): bool => data_get($button, 'type') === 'QUICK_REPLY',
        ));
        $index = array_search($text, array_map(static fn ($button): string => (string) data_get($button, 'text'), $quickReplies), true);

        if ($index === false) {
            return null;
        }

        foreach ((array) data_get($message?->envelope, 'template.components', []) as $component) {
            if (data_get($component, 'type') === 'button' && (string) data_get($component, 'index') === (string) $index) {
                $payload = data_get($component, 'parameters.0.payload');

                return is_string($payload) ? $payload : null;
            }
        }

        return null;
    }

    /**
     * The description of the tapped row, read from the list that was actually
     * sent. Meta echoes a row's description in `list_reply` only when the row
     * had one — so must the sandbox, or it would diverge from production.
     */
    protected function rowDescription(SandboxConversation $conversation, string $replyTo, string $id): ?string
    {
        $message = $conversation->messages()->where('wamid', $replyTo)->first();

        foreach ((array) data_get($message?->envelope, 'interactive.action.sections', []) as $section) {
            foreach ((array) data_get($section, 'rows', []) as $row) {
                if ((string) data_get($row, 'id') === $id) {
                    $description = data_get($row, 'description');

                    return is_string($description) && $description !== '' ? $description : null;
                }
            }
        }

        return null;
    }

    /**
     * Move a sent message along its delivery lifecycle (sent → delivered → read,
     * or → failed). Meta reports this on a separate webhook, minutes later; here
     * it is a button, so the path can be exercised on purpose.
     */
    public function advanceStatus(SandboxMessage $message, string $status, ?string $faultKey = null): SimulatedWebhook
    {
        $conversation = $message->conversation;
        $fault = $faultKey !== null ? FaultCatalog::find($faultKey) : null;

        $message->forceFill([
            'delivery_status' => $status,
            'error_code' => $fault?->code,
        ])->save();

        return $this->simulator->deliver(
            $this->factory($conversation)->status($message->wamid, $status, $fault),
        );
    }

    /**
     * Store the inbound message, open Meta's 24h window, then hand the payload to
     * the app through the real webhook route.
     *
     * The window opens BEFORE delivery on purpose: a listener that answers this
     * very message with free text must find the window already open, exactly as it
     * would in production.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliver(
        SandboxConversation $conversation,
        array $payload,
        string $type,
        string $rendered,
    ): SimulatedWebhook {
        $message = data_get($payload, 'entry.0.changes.0.value.messages.0', []);

        $conversation->openWindow();

        $row = SandboxMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => SandboxMessage::INBOUND,
            'wamid' => (string) data_get($message, 'id'),
            'type' => $type,
            'inbound_payload' => $payload,
            'rendered_text' => $rendered,
        ]);

        $result = $this->simulator->deliver($payload);

        // What the app's listeners did with it — and, if one of them threw, the
        // exception itself. Production would have buried it in a 500.
        $row->forceFill(['meta' => $result->toArray()])->save();

        return $result;
    }

    private function factory(SandboxConversation $conversation): InboundPayloadFactory
    {
        return InboundPayloadFactory::for(
            $this->whatsapp->credentials(),
            waId: $conversation->wa_id,
            profileName: $conversation->name,
        );
    }
}

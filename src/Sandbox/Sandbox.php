<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxConversation;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxMessage;
use Callcocam\WhatsAppCloud\WhatsAppManager;

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
     * The person taps a quick-reply button on a TEMPLATE. Comes back to the app as
     * `type: button` — not as an interactive reply.
     */
    public function tapTemplateButton(SandboxConversation $conversation, string $text, string $replyTo): SimulatedWebhook
    {
        return $this->deliver(
            $conversation,
            $this->factory($conversation)->templateButton($text, $replyTo),
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
            $this->factory($conversation)->listReply($id, $title, replyTo: $replyTo),
            type: 'interactive',
            rendered: $title,
        );
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

<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\SandboxException;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxConversation;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxMessage;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * The wire that goes nowhere: it stores the message instead of sending it, and
 * enforces the Meta rules that actually bite.
 *
 * It is not a null object. It says no in the same places Meta says no — the 24h
 * window above all — so an app that passes here has genuinely handled the failure
 * modes, rather than merely never having met them.
 */
class SandboxTransport implements MessageTransport
{
    public function __construct(
        private readonly TemplateResolver $templates,
        Application $app,
    ) {
        // No override, no flag, no "just this once". A sandbox driver left on in
        // production turns real messages into database rows: the customer never
        // hears from you and the app believes it sent. Silent, and expensive.
        if ($app->isProduction()) {
            throw new SandboxException(
                'The WhatsApp sandbox driver refuses to run in production. '
                .'Set WHATSAPP_CLOUD_DRIVER=cloud (and run `config:clear` and `queue:restart`).',
            );
        }

        if (! Schema::hasTable('whatsapp_sandbox_conversations')) {
            throw new SandboxException(
                'The WhatsApp sandbox tables are missing. Publish and run the migrations: '
                .'`php artisan vendor:publish --tag=whatsapp-cloud-sandbox-migrations && php artisan migrate`.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function postMessage(WhatsAppCredentials $credentials, array $envelope): array
    {
        $conversation = $this->conversation($credentials, (string) ($envelope['to'] ?? ''));
        $type = (string) ($envelope['type'] ?? 'text');

        $this->assertNoArmedFault($conversation);
        $this->assertWindowOpen($conversation, $type);

        $template = $type === 'template'
            ? $this->templates->resolve(
                (string) data_get($envelope, 'template.name'),
                (string) (data_get($envelope, 'template.language.code') ?? 'pt_BR'),
            )
            : null;

        $wamid = InboundPayloadFactory::wamid();

        SandboxMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => SandboxMessage::OUTBOUND,
            'wamid' => $wamid,
            'type' => $type,
            'envelope' => $envelope,
            'template_name' => $type === 'template' ? data_get($envelope, 'template.name') : null,
            'template_components' => $template?->components,
            'rendered_text' => $this->render($envelope, $type, $template),
            // Meta acknowledges the send; delivery is a separate webhook later.
            // The sandbox does NOT fire it from in here: a status listener that
            // replies would recurse inside the send it was triggered by. The UI
            // advances sent → delivered → read on purpose.
            'delivery_status' => 'sent',
            'meta' => array_filter([
                'template_source' => $template?->source,
                'template_unresolved' => $type === 'template' && $template === null,
                'param_count_mismatch' => $this->paramMismatch($envelope, $template),
            ]),
        ]);

        return ['messages' => [['id' => $wamid]]];
    }

    /**
     * A failure the developer armed, fired once and disarmed. Thrown as the very
     * same exception the real transport throws, so isTerminal() cannot disagree
     * between the sandbox and production.
     */
    private function assertNoArmedFault(SandboxConversation $conversation): void
    {
        $fault = $conversation->consumeFault();

        if ($fault instanceof Fault) {
            throw new CloudApiException(
                $fault->connection ? $fault->message : 'WhatsApp Cloud API error: '.$fault->message,
                $fault->code,
            );
        }
    }

    /**
     * Meta's 24h rule, enforced for real: outside the window only a template gets
     * through. Everything else comes back as 131047 — terminal, so the app must
     * log and stop rather than let the queue retry it forever.
     */
    private function assertWindowOpen(SandboxConversation $conversation, string $type): void
    {
        if ($type === 'template' || $conversation->windowIsOpen()) {
            return;
        }

        $fault = FaultCatalog::find('window_closed');

        throw new CloudApiException(
            'WhatsApp Cloud API error: '.$fault->message,
            $fault->code,
        );
    }

    /**
     * What the person on the other end actually reads.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function render(array $envelope, string $type, ?ResolvedTemplate $template): ?string
    {
        return match ($type) {
            'text' => (string) data_get($envelope, 'text.body'),
            'interactive' => (string) data_get($envelope, 'interactive.body.text'),
            'template' => $template?->render($this->params($envelope))
                // No definition file and Meta could not be reached: show what we
                // do know rather than an empty bubble.
                ?? trim((string) data_get($envelope, 'template.name').' '.implode(' · ', $this->params($envelope))),
            default => null,
        };
    }

    /**
     * The positional {{1}}, {{2}}… values, as they were actually sent.
     *
     * @param  array<string, mixed>  $envelope
     * @return list<string>
     */
    private function params(array $envelope): array
    {
        $parameters = (array) data_get($envelope, 'template.components.0.parameters', []);

        return array_values(array_map(
            static fn ($parameter): string => (string) data_get($parameter, 'text', ''),
            $parameters,
        ));
    }

    /**
     * The trap the docs call number three: the registry's param ORDER drifting
     * from the {{n}} in the body. In production it fails silently — the wrong
     * value simply reaches the customer. Here we can at least say so.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function paramMismatch(array $envelope, ?ResolvedTemplate $template): ?string
    {
        if (! $template instanceof ResolvedTemplate) {
            return null;
        }

        $sent = count($this->params($envelope));
        $expected = $template->variableCount();

        return $sent === $expected
            ? null
            : "The body has {$expected} variable(s) but {$sent} param(s) were sent.";
    }

    private function conversation(WhatsAppCredentials $credentials, string $to): SandboxConversation
    {
        if ($to === '') {
            throw new SandboxException('The message envelope has no recipient.');
        }

        return SandboxConversation::firstOrCreate(
            ['phone_number_id' => $credentials->phoneNumberId(), 'wa_id' => $to],
            ['name' => $to, 'role' => 'customer'],
        );
    }
}

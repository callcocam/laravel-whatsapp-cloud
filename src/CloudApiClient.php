<?php

namespace Callcocam\WhatsAppCloud;

use Callcocam\WhatsAppCloud\Contracts\MessageGateway;
use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Messages\InteractiveMessage;
use Callcocam\WhatsAppCloud\Messages\SendResult;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;
use Callcocam\WhatsAppCloud\Support\ArrayCredentials;
use Callcocam\WhatsAppCloud\Templates\TemplateRegistry;

/**
 * The official (Meta Cloud API) provider — the only WhatsApp channel. It speaks
 * messages only: Meta has no group or address-book concept. Talks to
 * {@link https://graph.facebook.com/{version}/{phone_number_id}/messages}.
 *
 * One instance is bound to one number's credentials; build it through
 * {@see CloudApiFactory} (or {@see WhatsAppManager::for()}).
 *
 * It builds envelopes; it does not own the wire. Delivery goes through the bound
 * {@see MessageTransport}, which the `whatsapp-cloud.driver` config selects — so
 * the sandbox can divert every message without this class knowing.
 */
class CloudApiClient implements MessageGateway
{
    public const PROVIDER = 'meta_cloud';

    public function __construct(
        protected readonly string $graphVersion,
        protected readonly string $phoneNumberId,
        protected readonly string $accessToken,
        protected readonly TemplateRegistry $templates,
        protected ?MessageTransport $transport = null,
    ) {}

    /**
     * Send an approved template (the only way to open a conversation outside the
     * 24h window). The app key resolves to the Meta template + ordered params.
     */
    public function sendTemplate(string $to, TemplateMessage $template): SendResult
    {
        $meta = $this->templates->resolve($template->key);

        $parameters = array_map(
            fn (string $name) => ['type' => 'text', 'text' => (string) ($template->params[$name] ?? '')],
            $meta->bodyParams,
        );

        $payload = [
            'name' => $meta->name,
            'language' => ['code' => $meta->language],
        ];

        if ($parameters !== []) {
            $payload['components'] = [['type' => 'body', 'parameters' => $parameters]];
        }

        return $this->send($to, ['type' => 'template', 'template' => $payload]);
    }

    /**
     * Free text — valid only inside the 24h session window. Meta returns error
     * 131047 when the window is closed; {@see CloudApiException} treats it as
     * terminal so the caller logs instead of retrying.
     */
    public function sendSessionText(string $to, string $text): SendResult
    {
        return $this->send($to, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    /**
     * A question with options, as a Meta interactive list. Row titles are capped
     * at Meta's 24-char limit with the full label kept in the description.
     */
    public function sendInteractive(string $to, InteractiveMessage $message): SendResult
    {
        $rows = [];

        foreach ($message->options as $index => $label) {
            $rows[] = [
                'id' => 'opt_'.$index,
                'title' => mb_substr($label, 0, 24),
                'description' => mb_substr($label, 0, 72),
            ];
        }

        return $this->send($to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $message->body],
                'action' => [
                    'button' => mb_substr($message->buttonLabel ?? __('Responder'), 0, 20),
                    'sections' => [['rows' => $rows]],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload  the type-specific part of the body
     */
    protected function send(string $to, array $payload): SendResult
    {
        $response = $this->transport()->postMessage($this->credentials(), array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ], $payload));

        $wamid = data_get($response, 'messages.0.id');

        return SendResult::sent(self::PROVIDER, is_string($wamid) ? $wamid : null);
    }

    /**
     * Resolved lazily, never in the constructor: a test (or the sandbox panel)
     * may flip `whatsapp-cloud.driver` after this client was already built.
     */
    protected function transport(): MessageTransport
    {
        return $this->transport ??= app(MessageTransport::class);
    }

    protected function credentials(): WhatsAppCredentials
    {
        return new ArrayCredentials($this->phoneNumberId, $this->accessToken, null, $this->graphVersion);
    }
}

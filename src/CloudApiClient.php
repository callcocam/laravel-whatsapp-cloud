<?php

namespace Callcocam\WhatsAppCloud;

use Callcocam\WhatsAppCloud\Contracts\MediaDownloader;
use Callcocam\WhatsAppCloud\Contracts\MediaUploader;
use Callcocam\WhatsAppCloud\Contracts\MessageGateway;
use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Messages\DownloadedMedia;
use Callcocam\WhatsAppCloud\Messages\InboundMedia;
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

        $components = [];

        if ($template->document !== null) {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => $template->document]]];
        }

        if ($parameters !== []) {
            $components[] = ['type' => 'body', 'parameters' => $parameters];
        }

        foreach ($template->buttonPayloads as $index => $buttonPayload) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) $index,
                'parameters' => [['type' => 'payload', 'payload' => mb_substr($buttonPayload, 0, 1000)]],
            ];
        }

        if ($components !== []) {
            $payload['components'] = $components;
        }

        return $this->send($to, ['type' => 'template', 'template' => $payload]);
    }

    /**
     * Upload a file to Meta and get the media id a template header refers to
     * ({@see TemplateMessage::withDocument()}). Under the sandbox driver nothing
     * leaves the machine.
     */
    public function uploadMedia(string $contents, string $mimeType, string $filename): string
    {
        return app(MediaUploader::class)->upload($this->credentials(), $contents, $mimeType, $filename);
    }

    /**
     * Download a file someone sent to the number ({@see InboundMedia::$id}).
     * `$maxBytes` defaults to `whatsapp-cloud.media.max_download_bytes`; an empty
     * `$allowedMimeTypes` accepts any type. Under the sandbox driver nothing
     * leaves the machine.
     *
     * @param  list<string>  $allowedMimeTypes
     */
    public function downloadMedia(string $mediaId, ?int $maxBytes = null, array $allowedMimeTypes = []): DownloadedMedia
    {
        $maxBytes ??= (int) config('whatsapp-cloud.media.max_download_bytes', 16 * 1024 * 1024);

        return app(MediaDownloader::class)->download($this->credentials(), $mediaId, $maxBytes, $allowedMimeTypes);
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
     * Meta's reply-button limits: at most 3 buttons, each title at most 20 chars.
     */
    public const MAX_REPLY_BUTTONS = 3;

    public const MAX_BUTTON_TITLE = 20;

    /**
     * A question with options. Up to 3 options that all fit a button title go as
     * Meta **reply buttons** (tapped right on the message); anything else goes as
     * an interactive **list**. Row titles are capped at Meta's 24-char limit;
     * only a label that does NOT fit gets a description (the full label, capped
     * at 72). WhatsApp shows title AND description — on the list and on the
     * person's reply bubble — so a description equal to the title prints the
     * option twice ("Confirmar / Confirmar").
     *
     * Option ids are `opt_{index}` for both shapes and apps map the reply by them
     * (`button_reply.id` or `list_reply.id`): keep them stable.
     */
    public function sendInteractive(string $to, InteractiveMessage $message): SendResult
    {
        if ($this->fitsReplyButtons($message->options)) {
            return $this->send($to, [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $message->body],
                    'action' => [
                        'buttons' => array_map(
                            fn (string $label, int $index): array => ['type' => 'reply', 'reply' => ['id' => 'opt_'.$index, 'title' => $label]],
                            $message->options,
                            array_keys($message->options),
                        ),
                    ],
                ],
            ]);
        }

        $rows = [];

        foreach ($message->options as $index => $label) {
            $row = [
                'id' => 'opt_'.$index,
                'title' => mb_substr($label, 0, 24),
            ];

            if (mb_strlen($label) > 24) {
                $row['description'] = mb_substr($label, 0, 72);
            }

            $rows[] = $row;
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
     * @param  list<string>  $options
     */
    private function fitsReplyButtons(array $options): bool
    {
        if ($options === [] || count($options) > self::MAX_REPLY_BUTTONS) {
            return false;
        }

        foreach ($options as $label) {
            if ($label === '' || mb_strlen($label) > self::MAX_BUTTON_TITLE) {
                return false;
            }
        }

        // Meta refuses two buttons with the same title.
        return count(array_unique($options)) === count($options);
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

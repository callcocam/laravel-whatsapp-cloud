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
use InvalidArgumentException;

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
     * Reply buttons with the app's own ids (Meta echoes the tapped id in
     * `button_reply.id`), optionally under an image header — e.g. a picture
     * with "Approve" / "Change" right below it. At most 3 buttons; titles are
     * capped at 20 chars and ids at 256.
     *
     * @param  array<string, string>  $buttons  id => title
     */
    public function sendButtons(string $to, string $body, array $buttons, ?string $imageUrl = null, ?string $footer = null): SendResult
    {
        if ($buttons === [] || count($buttons) > self::MAX_REPLY_BUTTONS) {
            throw new InvalidArgumentException('Reply buttons take 1 to '.self::MAX_REPLY_BUTTONS.' buttons.');
        }

        $rows = [];
        foreach ($buttons as $id => $title) {
            $rows[] = ['type' => 'reply', 'reply' => ['id' => mb_substr((string) $id, 0, 256), 'title' => mb_substr($title, 0, self::MAX_BUTTON_TITLE)]];
        }

        return $this->send($to, [
            'type' => 'interactive',
            'interactive' => array_filter([
                'type' => 'button',
                'header' => $imageUrl !== null ? ['type' => 'image', 'image' => ['link' => $imageUrl]] : null,
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'footer' => $footer !== null ? ['text' => mb_substr($footer, 0, 60)] : null,
                'action' => ['buttons' => $rows],
            ]),
        ]);
    }

    /**
     * An interactive list with the app's own row ids (Meta echoes the picked id
     * in `list_reply.id`) and an optional description per row. At most 10 rows;
     * titles are capped at 24 chars, descriptions at 72, ids at 200.
     *
     * @param  array<string, string|array{0: string, 1?: string|null}>  $rows  id => title, or id => [title, description]
     */
    public function sendList(string $to, string $body, string $buttonLabel, array $rows, ?string $sectionTitle = null): SendResult
    {
        if ($rows === [] || count($rows) > 10) {
            throw new InvalidArgumentException('A list takes 1 to 10 rows.');
        }

        $listRows = [];
        foreach ($rows as $id => $row) {
            [$title, $description] = is_array($row) ? [$row[0], $row[1] ?? null] : [$row, null];
            $listRows[] = array_filter([
                'id' => mb_substr((string) $id, 0, 200),
                'title' => mb_substr($title, 0, 24),
                'description' => $description !== null && $description !== '' ? mb_substr($description, 0, 72) : null,
            ]);
        }

        return $this->send($to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => [
                    'button' => mb_substr($buttonLabel, 0, 20),
                    'sections' => [array_filter([
                        'title' => $sectionTitle !== null ? mb_substr($sectionTitle, 0, 24) : null,
                        'rows' => $listRows,
                    ])],
                ],
            ],
        ]);
    }

    /**
     * An image by public link, with an optional caption (inside the 24h
     * session window, like any free-form message).
     */
    public function sendImage(string $to, string $imageUrl, ?string $caption = null): SendResult
    {
        return $this->send($to, [
            'type' => 'image',
            'image' => array_filter(['link' => $imageUrl, 'caption' => $caption !== null ? mb_substr($caption, 0, 1024) : null]),
        ]);
    }

    /**
     * How Meta sees a file: image, video, audio or document. Meta only takes
     * some formats (and sizes) as each kind; the rest goes as a document,
     * which accepts anything up to 100 MB.
     *
     * @return 'image'|'video'|'audio'|'document'
     */
    public static function mediaTypeFor(string $mimeType, int $size = 0): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        $kinds = [
            'image' => [['image/jpeg', 'image/png'], 5 * 1024 * 1024],
            'video' => [['video/mp4', 'video/3gpp'], 16 * 1024 * 1024],
            'audio' => [['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'], 16 * 1024 * 1024],
        ];

        foreach ($kinds as $type => [$mimes, $limit]) {
            if (in_array($mimeType, $mimes, true) && $size <= $limit) {
                return $type;
            }
        }

        return 'document';
    }

    /**
     * Send a file the app holds: uploads it to Meta and sends it as the kind
     * {@see mediaTypeFor()} picks (inside the 24h session window). The caption
     * only exists for image, video and document; the file name only shows on
     * a document.
     */
    public function sendFile(string $to, string $contents, string $mimeType, string $filename, ?string $caption = null): SendResult
    {
        $type = self::mediaTypeFor($mimeType, strlen($contents));
        $mediaId = $this->uploadMedia($contents, $mimeType, $filename);

        $media = ['id' => $mediaId];

        if ($type !== 'audio' && $caption !== null && $caption !== '') {
            $media['caption'] = mb_substr($caption, 0, 1024);
        }

        if ($type === 'document') {
            $media['filename'] = mb_substr($filename, 0, 240);
        }

        return $this->send($to, ['type' => $type, $type => $media]);
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

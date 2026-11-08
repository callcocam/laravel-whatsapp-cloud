<?php

namespace Callcocam\WhatsAppCloud\Templates;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Contracts\MessageTransport;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Support\ArrayCredentials;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Meta template-management API (create/list/get/delete a template, send an
 * approved one). Bound to one WABA's credentials — build it through
 * {@see WhatsAppManager::templateApi()}.
 *
 * This is the "control plane" (message_templates endpoints), separate from the
 * "data plane" {@see CloudApiClient} that sends runtime
 * messages.
 *
 * The management calls always hit Meta for real — a template is a real object on
 * a real WABA, there is nothing to simulate. Only {@see send()} goes through the
 * {@see MessageTransport}, because it delivers an actual message to an actual
 * person: under the sandbox driver the panel's "send test" button must NOT reach
 * a real phone.
 */
final class TemplateManager
{
    /**
     * Marker {@see TemplateBuilder::headerDocument()} leaves in a header's example:
     * a local file that {@see create()} uploads and replaces by Meta's handle.
     */
    public const EXAMPLE_FILE = 'header_handle_file';

    public function __construct(
        private readonly string $graphVersion,
        private readonly string $wabaId,
        private readonly string $accessToken,
        private readonly ?string $phoneNumberId = null,
        private ?MessageTransport $transport = null,
        private readonly ?string $appId = null,
    ) {}

    /**
     * Create a template on the WABA (submits it for Meta review).
     *
     * @param  array<string, mixed>  $payload  usually from TemplateBuilder::toArray()
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $payload = $this->withUploadedExamples($payload);

        return $this->handle(fn () => $this->request()->post("{$this->wabaId}/message_templates", $payload))->json();
    }

    /**
     * Upload an example file for a media header review and get its handle
     * (Resumable Upload API: open a session on the app, then send the bytes).
     */
    public function uploadExample(string $path): string
    {
        if (blank($this->appId)) {
            throw new CloudApiException('Uploading a header example needs the Meta app id (`whatsapp-cloud.app_id`).');
        }

        if (! is_file($path)) {
            throw new CloudApiException("Header example file not found: {$path}");
        }

        $contents = (string) file_get_contents($path);
        $type = mime_content_type($path) ?: 'application/octet-stream';

        $session = $this->handle(fn () => $this->request()->post("{$this->appId}/uploads?".http_build_query([
            'file_name' => basename($path),
            'file_length' => strlen($contents),
            'file_type' => $type,
        ])))->json('id');

        $handle = $this->handle(fn () => Http::baseUrl("https://graph.facebook.com/{$this->graphVersion}")
            ->withHeaders(['Authorization' => "OAuth {$this->accessToken}", 'file_offset' => '0'])
            ->acceptJson()
            ->timeout(60)
            ->withBody($contents, $type)
            ->post((string) $session))->json('h');

        if (! is_string($handle) || $handle === '') {
            throw new CloudApiException('Meta answered the example upload without a handle.');
        }

        return $handle;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withUploadedExamples(array $payload): array
    {
        foreach ((array) ($payload['components'] ?? []) as $index => $component) {
            $file = data_get($component, 'example.'.self::EXAMPLE_FILE);

            if (is_string($file)) {
                $payload['components'][$index]['example'] = ['header_handle' => [$this->uploadExample($file)]];
            }
        }

        return $payload;
    }

    /**
     * Edit an existing template by its id (components and/or category).
     *
     * Meta only allows editing an APPROVED, REJECTED or PAUSED template (never a
     * PENDING one); a successful edit resets it to PENDING for re-review. The
     * template `name` and `language` are NOT editable.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function edit(string $id, array $components, ?string $category = null): array
    {
        $payload = ['components' => $components];

        if ($category !== null && $category !== '') {
            $payload['category'] = strtoupper($category);
        }

        return $this->handle(fn () => $this->request()->post($id, $payload))->json();
    }

    /**
     * List the WABA templates (optionally filtered by name).
     *
     * @return array<string, mixed>
     */
    public function all(?string $name = null, int $limit = 100): array
    {
        $query = ['limit' => $limit];

        if ($name !== null) {
            $query['name'] = $name;
        }

        return $this->handle(fn () => $this->request()->get("{$this->wabaId}/message_templates", $query))->json();
    }

    /**
     * Fetch a template by name (first language found), or null.
     *
     * @return array<string, mixed>|null
     */
    public function getByName(string $name): ?array
    {
        $data = $this->all($name, 1)['data'] ?? [];

        return is_array($data[0] ?? null) ? $data[0] : null;
    }

    /**
     * Remove a template by name (all languages) or a specific id.
     *
     * @return array<string, mixed>
     */
    public function delete(string $name, ?string $id = null): array
    {
        $query = ['name' => $name];

        if ($id !== null) {
            $query['hsm_id'] = $id;
        }

        return $this->handle(fn () => $this->request()->delete("{$this->wabaId}/message_templates", $query))->json();
    }

    /**
     * Send a message using an approved template.
     *
     * Goes through the {@see MessageTransport} — this is a real message to a real
     * person, so the sandbox driver has to be able to intercept it.
     *
     * @param  array<int, string|int|float>  $bodyParams  positional values for {{1}}, {{2}}…
     * @return array<string, mixed>
     */
    public function send(string $templateName, string $to, array $bodyParams = [], string $language = 'pt_BR'): array
    {
        if (blank($this->phoneNumberId)) {
            throw new CloudApiException('A phone number id is required to send messages.');
        }

        $template = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];

        if ($bodyParams !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    static fn ($value): array => ['type' => 'text', 'text' => (string) $value],
                    array_values($bodyParams),
                ),
            ]];
        }

        return $this->transport()->postMessage($this->credentials(), [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /**
     * Resolved lazily — see {@see CloudApiClient::transport()}.
     */
    private function transport(): MessageTransport
    {
        return $this->transport ??= app(MessageTransport::class);
    }

    private function credentials(): ArrayCredentials
    {
        return new ArrayCredentials(
            (string) $this->phoneNumberId,
            $this->accessToken,
            $this->wabaId,
            $this->graphVersion,
        );
    }

    /**
     * Estimated conversation cost for a time window, grouped by category.
     *
     * Reads the WABA `conversation_analytics` edge (COST + CONVERSATION metrics).
     * The `cost` is an ESTIMATE in the account's billing currency — the source of
     * truth for charges is the WhatsApp Manager / Meta billing. The access token
     * needs the `whatsapp_business_management` permission.
     *
     * @param  int  $start  window start (unix seconds)
     * @param  int  $end  window end (unix seconds)
     * @return array<string, mixed>
     */
    public function costs(int $start, int $end, string $granularity = 'MONTHLY'): array
    {
        $fields = "conversation_analytics.start({$start}).end({$end})"
            .".granularity({$granularity}).metric_types(['COST','CONVERSATION'])"
            .".dimensions(['CONVERSATION_CATEGORY'])";

        return $this->handle(fn () => $this->request()->get($this->wabaId, ['fields' => $fields]))->json();
    }

    /**
     * @param  callable(): Response  $callback
     */
    private function handle(callable $callback): Response
    {
        try {
            $response = $callback();
        } catch (ConnectionException $exception) {
            throw new CloudApiException('Could not connect to the WhatsApp Cloud API.', previous: $exception);
        }

        if ($response->failed()) {
            throw CloudApiException::fromResponse($response);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl("https://graph.facebook.com/{$this->graphVersion}")
            ->withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30);
    }
}

<?php

namespace Callcocam\WhatsAppCloud\Templates;

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
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
 */
final class TemplateManager
{
    public function __construct(
        private readonly string $graphVersion,
        private readonly string $wabaId,
        private readonly string $accessToken,
        private readonly ?string $phoneNumberId = null,
    ) {}

    /**
     * Create a template on the WABA (submits it for Meta review).
     *
     * @param  array<string, mixed>  $payload  usually from TemplateBuilder::toArray()
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        return $this->handle(fn () => $this->request()->post("{$this->wabaId}/message_templates", $payload))->json();
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

        return $this->handle(fn () => $this->request()->post("{$this->phoneNumberId}/messages", [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]))->json();
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

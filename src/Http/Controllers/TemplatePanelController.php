<?php

namespace Callcocam\WhatsAppCloud\Http\Controllers;

use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppException;
use Callcocam\WhatsAppCloud\Templates\TemplateInput;
use Callcocam\WhatsAppCloud\Templates\TemplateManager;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use LogicException;

/**
 * The template-management panel (Inertia + Vue). A thin HTTP adapter over the
 * {@see TemplateManager} — it does no Meta protocol work itself, delegating
 * payload building/validation to {@see TemplateInput} and the API calls to the
 * manager, exactly as the `whatsapp:template:*` commands do.
 *
 * Mutations perform the action and redirect back; Inertia reloads the page
 * props, so the table refreshes with the new list. Errors surface as Inertia
 * validation errors (`errors.meta` for Meta failures, `errors.form` for local
 * guard-rail rejections).
 */
class TemplatePanelController
{
    /**
     * The panel page: the current WABA templates plus the public credentials.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->guardUiToken($request);

        $templates = [];
        $loadError = null;

        try {
            $templates = $this->manager($request)->all()['data'] ?? [];
        } catch (WhatsAppException $e) {
            $loadError = $e->getMessage();
        }

        return Inertia::render('WhatsAppCloud/Templates/Index', [
            'templates' => array_values($templates),
            'waConfig' => $this->publicConfig($request),
            'loadError' => $loadError,
            'panelUrl' => route($this->routeName()),
        ]);
    }

    /**
     * Create a template (submits it to Meta for review).
     */
    public function store(Request $request): RedirectResponse
    {
        $this->guardUiToken($request);

        return $this->run(function () use ($request): RedirectResponse {
            $payload = TemplateInput::toPayload($request->all());
            $this->manager($request)->create($payload);

            return back()->with('flash', [
                'success' => "Template \"{$payload['name']}\" enviado para análise.",
            ]);
        });
    }

    /**
     * Edit an existing template by id (resets it to PENDING for re-review).
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $this->guardUiToken($request);

        return $this->run(function () use ($request, $id): RedirectResponse {
            $payload = TemplateInput::toPayload($request->all());
            $this->manager($request)->edit($id, $payload['components'], $payload['category'] ?? null);

            return back()->with('flash', [
                'success' => "Template \"{$payload['name']}\" enviado para nova análise.",
            ]);
        });
    }

    /**
     * Delete a template by name — removes ALL languages with that name.
     */
    public function destroy(Request $request, string $name): RedirectResponse
    {
        $this->guardUiToken($request);

        return $this->run(function () use ($request, $name): RedirectResponse {
            $this->manager($request)->delete($name);

            return back()->with('flash', ['success' => "Template \"{$name}\" apagado."]);
        });
    }

    /**
     * Send a test message using an approved template.
     */
    public function send(Request $request): RedirectResponse
    {
        $this->guardUiToken($request);

        return $this->run(function () use ($request): RedirectResponse {
            $name = trim((string) $request->input('name', ''));
            $to = preg_replace('/\D+/', '', (string) $request->input('to', '')) ?? '';
            $language = trim((string) $request->input('language', 'pt_BR')) ?: 'pt_BR';
            $params = TemplateInput::normalizeExamples($request->input('params', []));

            if ($name === '') {
                return back()->withErrors(['form' => 'Informe o nome do template.']);
            }

            if (! preg_match('/^\d{8,15}$/', $to)) {
                return back()->withErrors([
                    'form' => 'Número de destino inválido (use só dígitos, ex.: 5548999999999).',
                ]);
            }

            $result = $this->manager($request)->send($name, $to, $params, $language);
            $id = data_get($result, 'messages.0.id');

            return back()->with('flash', [
                'success' => "Mensagem enviada para {$to}.",
                'sent_id' => is_string($id) ? $id : null,
            ]);
        });
    }

    /**
     * Run a mutation, mapping the two failure families to Inertia validation
     * errors: Meta/API errors → `meta`, local guard-rail rejections → `form`.
     *
     * @param  callable(): RedirectResponse  $action
     */
    private function run(callable $action): RedirectResponse
    {
        try {
            return $action();
        } catch (CloudApiException $e) {
            $code = $e->errorCode !== null ? " (code {$e->errorCode})" : '';

            return back()->withErrors(['meta' => $e->getMessage().$code]);
        } catch (WhatsAppException $e) {
            return back()->withErrors(['meta' => $e->getMessage()]);
        } catch (InvalidArgumentException|LogicException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }
    }

    /**
     * The template API for the resolved tenant. For now this always uses the
     * default credentials; this single method is the hook for a future tenant
     * selector (e.g. `$request->query('tenant')`).
     */
    private function manager(Request $request): TemplateManager
    {
        return app(WhatsAppManager::class)->templateApi($this->tenant($request));
    }

    /**
     * Public (non-secret) credentials shown in the panel header. Never exposes
     * the access token.
     *
     * @return array<string, string|null>
     */
    private function publicConfig(Request $request): array
    {
        try {
            $credentials = app(WhatsAppManager::class)->credentials($this->tenant($request));

            return [
                'waba_id' => $credentials->wabaId(),
                'phone_number_id' => $credentials->phoneNumberId(),
                'api_version' => $credentials->graphVersion() ?? (string) config('whatsapp-cloud.graph_version'),
            ];
        } catch (WhatsAppException) {
            return ['waba_id' => null, 'phone_number_id' => null, 'api_version' => null];
        }
    }

    private function tenant(Request $request): mixed
    {
        // Prototype: always the config `default` tenant. Wire a selector here
        // (e.g. return $request->query('tenant')) when going multi-tenant.
        return null;
    }

    private function routeName(): string
    {
        return (string) config('whatsapp-cloud.panel.name', 'whatsapp.cloud.panel').'.index';
    }

    /**
     * Optional defense-in-depth: when `panel.ui_token` is set every request must
     * carry the same value in the X-WA-UI-Token header.
     */
    private function guardUiToken(Request $request): void
    {
        $expected = config('whatsapp-cloud.panel.ui_token');

        if (blank($expected)) {
            return;
        }

        $provided = (string) $request->header('X-WA-UI-Token', '');

        abort_unless(
            $provided !== '' && hash_equals((string) $expected, $provided),
            401,
            'Não autorizado — informe o WA_UI_TOKEN.',
        );
    }
}

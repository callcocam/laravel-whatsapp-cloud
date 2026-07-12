<?php

namespace Callcocam\WhatsAppCloud\Http\Controllers;

use Callcocam\WhatsAppCloud\Exceptions\WhatsAppException;
use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Sandbox\Fault;
use Callcocam\WhatsAppCloud\Sandbox\FaultCatalog;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxConversation;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxMessage;
use Callcocam\WhatsAppCloud\Sandbox\ResolvedTemplate;
use Callcocam\WhatsAppCloud\Sandbox\Sandbox;
use Callcocam\WhatsAppCloud\Sandbox\TemplateDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The sandbox screen: a WhatsApp-looking chat you can act out both sides of, plus
 * an inspector showing exactly what went on the wire.
 *
 * A thin adapter. Everything it can do, {@see Sandbox} can do from a test or from
 * tinker — the screen is a window onto the engine, not the engine.
 */
class SandboxController
{
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly TemplateDefinitions $definitions,
    ) {}

    public function index(): Response
    {
        return Inertia::render('WhatsAppCloud/Sandbox/Index', $this->payload());
    }

    /**
     * Polled by the screen. Carries no raw payloads — see {@see message()}.
     */
    public function state(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->integer('conversation') ?: null));
    }

    /**
     * One message, in full: the exact envelope we posted, the exact webhook body,
     * the signature, the listeners, and any exception a listener threw.
     */
    public function message(SandboxMessage $message): JsonResponse
    {
        return response()->json([
            'id' => $message->id,
            'envelope' => $message->envelope,
            'inbound_payload' => $message->inbound_payload,
            'meta' => $message->meta,
        ]);
    }

    public function storeParticipant(Request $request): RedirectResponse
    {
        $waId = preg_replace('/\D+/', '', (string) $request->input('wa_id')) ?? '';

        if (! preg_match('/^\d{8,15}$/', $waId)) {
            return back()->withErrors(['wa_id' => 'Informe um número com 8 a 15 dígitos (com DDI e DDD).']);
        }

        $this->sandbox->participant(
            $waId,
            trim((string) $request->input('name')) ?: $waId,
            (string) $request->input('role', 'customer'),
        );

        return back();
    }

    public function reply(Request $request): RedirectResponse
    {
        return $this->run(fn () => $this->sandbox->reply(
            $this->conversation($request),
            (string) $request->input('text'),
            $this->replyTo($request),
        ));
    }

    /**
     * A tap on a button. Which webhook shape that produces depends on what it was
     * attached to — a template button is not an interactive button, and an app has
     * to handle both.
     */
    public function tap(Request $request): RedirectResponse
    {
        $conversation = $this->conversation($request);
        $replyTo = (string) $request->input('reply_to');
        $text = (string) $request->input('text');

        return $this->run(fn () => $request->input('kind') === 'list'
            ? $this->sandbox->pickListRow($conversation, (string) $request->input('id'), $text, $replyTo)
            : $this->sandbox->tapTemplateButton($conversation, $text, $replyTo));
    }

    /**
     * Send a template AS THE SYSTEM. The same call the app makes — it goes through
     * WhatsApp::for(), the registry and the transport, so nothing here is a
     * shortcut around the real path.
     */
    public function sendTemplate(Request $request): RedirectResponse
    {
        $conversation = $this->conversation($request);

        /** @var list<string> $params */
        $params = array_values(array_map('strval', (array) $request->input('params', [])));

        return $this->run(function () use ($request, $conversation, $params) {
            WhatsApp::templateApi()->send(
                (string) $request->input('name'),
                $conversation->wa_id,
                $params,
                (string) $request->input('language', 'pt_BR'),
            );
        });
    }

    public function sendText(Request $request): RedirectResponse
    {
        $conversation = $this->conversation($request);

        return $this->run(fn () => WhatsApp::for()->sendSessionText(
            $conversation->wa_id,
            (string) $request->input('text'),
        ));
    }

    public function status(Request $request): RedirectResponse
    {
        $message = SandboxMessage::findOrFail($request->integer('message'));

        return $this->run(fn () => $this->sandbox->advanceStatus(
            $message,
            (string) $request->input('status'),
            $request->input('fault') ? (string) $request->input('fault') : null,
        ));
    }

    public function arm(Request $request): RedirectResponse
    {
        $this->conversation($request)->arm((string) $request->input('fault'));

        return back();
    }

    public function closeWindow(Request $request): RedirectResponse
    {
        $this->conversation($request)->closeWindow();

        return back();
    }

    public function reset(): RedirectResponse
    {
        SandboxMessage::query()->delete();
        SandboxConversation::query()->delete();

        return back();
    }

    /**
     * Run an action and turn a WhatsApp failure into something the screen shows,
     * rather than a 500.
     *
     * A CloudApiException here is usually the sandbox WORKING — a closed window, an
     * armed fault. It belongs on the screen next to the message, not in a log.
     *
     * @param  callable(): mixed  $action
     */
    private function run(callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (WhatsAppException $exception) {
            return back()->with('flash', [
                'error' => $exception->getMessage(),
                'terminal' => $exception->isTerminal(),
            ]);
        } catch (Throwable $exception) {
            return back()->withErrors(['sandbox' => $exception->getMessage()]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?int $selected = null): array
    {
        $conversations = SandboxConversation::query()->orderBy('id')->get();
        $selected ??= $conversations->first()?->id;

        return [
            'conversations' => $conversations->map(fn (SandboxConversation $c): array => [
                'id' => $c->id,
                'wa_id' => $c->wa_id,
                'name' => $c->name,
                'role' => $c->role,
                'window_open' => $c->windowIsOpen(),
                'window_expires_at' => $c->window_expires_at?->toIso8601String(),
                'armed_fault' => $c->armedFault()?->key,
            ])->all(),

            'selected' => $selected,

            'messages' => $selected === null ? [] : SandboxMessage::query()
                ->where('conversation_id', $selected)
                ->orderBy('id')
                ->get()
                ->map(fn (SandboxMessage $m): array => $this->presentMessage($m))
                ->all(),

            'faults' => array_values(array_map(fn (Fault $f): array => [
                'key' => $f->key,
                'code' => $f->code,
                'title' => $f->title,
                'terminal' => $f->isTerminal(),
            ], FaultCatalog::all())),

            // What you can send. These come from the definition files — a template
            // shows up here BEFORE it exists on Meta, which is the point.
            'templates' => array_map(static fn (array $d): array => [
                'name' => $d['name'],
                'language' => $d['language'] ?? 'pt_BR',
                'variables' => (new ResolvedTemplate(
                    (string) $d['name'],
                    (string) ($d['language'] ?? 'pt_BR'),
                    array_values((array) ($d['components'] ?? [])),
                    ResolvedTemplate::SOURCE_DEFINITION,
                ))->variableCount(),
            ], $this->definitions->all()),

            'business' => [
                'phone_number_id' => WhatsApp::credentials()->phoneNumberId(),
                'display_phone_number' => (string) config('whatsapp-cloud.sandbox.display_phone_number'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMessage(SandboxMessage $message): array
    {
        $template = $message->template_components === null ? null : new ResolvedTemplate(
            (string) $message->template_name,
            'pt_BR',
            array_values($message->template_components),
            (string) ($message->meta['template_source'] ?? ResolvedTemplate::SOURCE_DEFINITION),
        );

        return [
            'id' => $message->id,
            'direction' => $message->direction,
            'type' => $message->type,
            'wamid' => $message->wamid,
            'text' => $message->rendered_text,
            'status' => $message->delivery_status,
            'error_code' => $message->error_code,
            'at' => $message->created_at?->toIso8601String(),

            'template' => $template === null ? null : [
                'name' => $message->template_name,
                'header' => $template->headerText(),
                'footer' => $template->footerText(),
                'buttons' => $template->buttons(),
                'source' => $template->source,
            ],

            // The interactive list, so its rows can be tapped.
            'options' => array_values(array_map(
                static fn ($row): array => [
                    'id' => (string) data_get($row, 'id'),
                    'title' => (string) data_get($row, 'title'),
                ],
                (array) data_get($message->envelope, 'interactive.action.sections.0.rows', []),
            )),

            'warnings' => array_values(array_filter([
                $message->meta['param_count_mismatch'] ?? null,
                ($message->meta['template_unresolved'] ?? false)
                    ? 'Sem arquivo de definição e a Meta não respondeu — a bolha mostra só o nome e os params.'
                    : null,
                ($message->meta['failure'] ?? null)
                    ? 'Um listener do app lançou: '.($message->meta['failure']['message'] ?? '')
                    : null,
            ])),

            'listeners' => $message->meta['listeners'] ?? [],
        ];
    }

    private function conversation(Request $request): SandboxConversation
    {
        return SandboxConversation::findOrFail($request->integer('conversation'));
    }

    private function replyTo(Request $request): ?string
    {
        $replyTo = $request->input('reply_to');

        return is_string($replyTo) && $replyTo !== '' ? $replyTo : null;
    }
}

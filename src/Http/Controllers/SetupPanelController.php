<?php

namespace Callcocam\WhatsAppCloud\Http\Controllers;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppException;
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppNotConfiguredException;
use Callcocam\WhatsAppCloud\Http\Controllers\Concerns\GuardsPanelUiToken;
use Callcocam\WhatsAppCloud\Models\WhatsAppInboundMessage;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Callcocam\WhatsAppCloud\Onboarding\EmbeddedSignup;
use Callcocam\WhatsAppCloud\Settings\SettingsStore;
use Callcocam\WhatsAppCloud\Setup\MetaAppClient;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The setup wizard: takes an install from nothing to a working number, storing
 * every credential encrypted through {@see SettingsStore} instead of the .env.
 *
 *  1. Meta app  — app id + secret, checked against Meta before saving.
 *  2. Webhook   — verify token generated, subscription registered by API.
 *  3. Signup    — the Embedded Signup `config_id` (created by hand at Meta).
 *  4. Number    — connected via Embedded Signup or typed in, then made default.
 *  5. Test      — send a template, watch the reply arrive.
 *
 * Plus a diagnosis that re-checks all of it against Meta, and a JSON
 * export/import to carry the configuration between projects.
 */
class SetupPanelController
{
    use GuardsPanelUiToken;

    private const NOTICE_KEY = 'whatsapp_cloud_setup_notice';

    private const DIAGNOSTICS_KEY = 'whatsapp_cloud_setup_diagnostics';

    public function __construct(protected readonly SettingsStore $settings) {}

    public function index(Request $request): InertiaResponse
    {
        $this->guardUiToken($request);

        $numbers = $this->numbers();
        $defaultPhone = (string) config('whatsapp-cloud.default.phone_number_id');
        $lastInbound = $this->lastInbound();

        return Inertia::render($this->component(), [
            'settings' => $this->maskedSettings(),
            'steps' => [
                'app' => filled(config('whatsapp-cloud.app_id')) && filled(config('whatsapp-cloud.app_secret')),
                'webhook' => filled(config('whatsapp-cloud.setup.webhook_subscribed_at')),
                'signup' => filled(config('whatsapp-cloud.embedded_signup.config_id')),
                'number' => filled($defaultPhone) && filled(config('whatsapp-cloud.default.access_token')),
                'test' => $lastInbound !== null,
            ],
            'webhook' => [
                'callback_url' => $this->callbackUrl(),
                'subscribed_at' => config('whatsapp-cloud.setup.webhook_subscribed_at'),
            ],
            'sdkDomain' => $request->getSchemeAndHttpHost(),
            'numbers' => array_map(fn (array $n) => $n + ['is_default' => $n['phone_number_id'] === $defaultPhone], $numbers),
            'lastInbound' => $lastInbound,
            'notice' => $request->session()->get(self::NOTICE_KEY),
            'diagnostics' => $request->session()->get(self::DIAGNOSTICS_KEY),
            'links' => [
                'numbers' => $this->routeIfExists(config('whatsapp-cloud.embedded_signup.name', 'whatsapp.cloud.numbers').'.index'),
                'templates' => $this->routeIfExists(config('whatsapp-cloud.panel.name', 'whatsapp.cloud.panel').'.index'),
            ],
            'panelUrl' => route($this->routeName('index')),
        ]);
    }

    /**
     * Step 1 — app id + secret. Checked against Meta BEFORE saving, so a typo
     * never lands in the store. A blank secret keeps the current one.
     */
    public function saveApp(Request $request): RedirectResponse
    {
        $this->guardUiToken($request);

        $appId = trim((string) $request->input('app_id', ''));
        $secret = trim((string) $request->input('app_secret', '')) ?: (string) config('whatsapp-cloud.app_secret');
        $version = trim((string) $request->input('graph_version', '')) ?: (string) config('whatsapp-cloud.graph_version', 'v21.0');

        if (! ctype_digit($appId) || $secret === '') {
            return back()->withErrors(['form' => 'Informe o App ID (só números) e o App Secret.']);
        }

        if (! preg_match('/^v\d+\.\d+$/', $version)) {
            return back()->withErrors(['form' => 'Versão da Graph API inválida (ex.: v21.0).']);
        }

        return $this->run(function () use ($appId, $secret, $version): RedirectResponse {
            $app = (new MetaAppClient($version, $appId, $secret))->app();

            $this->settings->put(['app_id' => $appId, 'app_secret' => $secret, 'graph_version' => $version]);

            return $this->ok('App "'.($app['name'] ?? $appId).'" validado e salvo.');
        });
    }

    /**
     * Step 2 — register this app's webhook at Meta by API. The verify token is
     * saved first: Meta calls the callback while this request is still open.
     */
    public function subscribeWebhook(Request $request): RedirectResponse
    {
        $this->guardUiToken($request);

        $callback = trim((string) $request->input('callback_url', '')) ?: $this->callbackUrl();

        if ($callback === null || ! str_starts_with($callback, 'https://') || filter_var($callback, FILTER_VALIDATE_URL) === false) {
            return back()->withErrors(['form' => 'A Meta exige uma URL de callback HTTPS pública.']);
        }

        return $this->run(function () use ($callback): RedirectResponse {
            $client = $this->appClient();

            if (blank(config('whatsapp-cloud.verify_token'))) {
                $this->settings->put(['verify_token' => Str::random(40)]);
            }

            $client->subscribeWebhook($callback, (string) config('whatsapp-cloud.verify_token'));
            $this->settings->put(['webhook_subscribed_at' => now()->toIso8601String()]);

            return $this->ok('Webhook registrado na Meta.');
        });
    }

    /**
     * Step 3 — the Embedded Signup configuration id (+ optional register PIN).
     */
    public function saveSignup(Request $request): RedirectResponse
    {
        $this->guardUiToken($request);

        $configId = trim((string) $request->input('config_id', ''));
        $pin = trim((string) $request->input('register_pin', ''));

        if (! ctype_digit($configId)) {
            return back()->withErrors(['form' => 'O config_id tem só números (Identificação da configuração).']);
        }

        if ($pin !== '' && ! preg_match('/^\d{6}$/', $pin)) {
            return back()->withErrors(['form' => 'O PIN precisa ter exatamente 6 dígitos.']);
        }

        $this->settings->put(['embedded_signup_config_id' => $configId, 'register_pin' => $pin]);

        return $this->ok('Embedded Signup configurado.');
    }

    /**
     * Step 4 (manual) — a number typed in by hand, checked against its WABA,
     * stored like an Embedded Signup one and optionally made the default.
     */
    public function saveNumber(Request $request, EmbeddedSignup $signup): RedirectResponse
    {
        $this->guardUiToken($request);

        $phoneNumberId = trim((string) $request->input('phone_number_id', ''));
        $wabaId = trim((string) $request->input('waba_id', ''));
        $token = trim((string) $request->input('access_token', ''));
        $key = trim((string) $request->input('key', '')) ?: null;

        if (! ctype_digit($phoneNumberId) || ! ctype_digit($wabaId) || $token === '') {
            return back()->withErrors(['form' => 'Informe o Phone Number ID, o WABA ID (só números) e o token.']);
        }

        return $this->run(function () use ($signup, $phoneNumberId, $wabaId, $token, $key, $request): RedirectResponse {
            $phone = $signup->findPhoneNumber($wabaId, $phoneNumberId, $token);

            $number = $this->model()::query()->firstOrNew(['phone_number_id' => $phoneNumberId]);
            $number->fill(array_filter([
                'key' => $key,
                'waba_id' => $wabaId,
                'cloud_access_token' => $token,
                'display_phone_number' => $phone['display_phone_number'] ?? null,
                'verified_name' => $phone['verified_name'] ?? null,
                'quality_rating' => $phone['quality_rating'] ?? null,
                'connected_at' => now(),
            ], fn ($v) => $v !== null))->save();

            $warnings = [];

            try {
                $signup->subscribeApp($wabaId, $token);
            } catch (CloudApiException $e) {
                $warnings[] = 'Não foi possível inscrever o app na WABA (webhooks): '.$e->getMessage();
            }

            if ($request->boolean('make_default')) {
                $this->makeDefault($number);
            }

            return $this->ok('Número '.($phone['display_phone_number'] ?? $phoneNumberId).' salvo.', $warnings);
        });
    }

    /**
     * Step 4 — which stored number `WhatsApp::for()` (no tenant) sends from.
     */
    public function setDefault(Request $request, string $number): RedirectResponse
    {
        $this->guardUiToken($request);

        $record = $this->model()::query()->find($number);
        abort_unless($record instanceof WhatsAppCredentials, 404);

        $this->makeDefault($record);

        return $this->ok('Número padrão definido.');
    }

    /**
     * Step 5 — send an approved template from the default number.
     */
    public function sendTest(Request $request, WhatsAppManager $whatsapp): RedirectResponse
    {
        $this->guardUiToken($request);

        $to = preg_replace('/\D+/', '', (string) $request->input('to', '')) ?? '';
        $template = trim((string) $request->input('template', '')) ?: 'hello_world';
        $language = trim((string) $request->input('language', '')) ?: 'en_US';

        if (! preg_match('/^\d{8,15}$/', $to)) {
            return back()->withErrors(['form' => 'Número de destino inválido (só dígitos com DDI, ex.: 5548999999999).']);
        }

        return $this->run(function () use ($whatsapp, $to, $template, $language): RedirectResponse {
            $whatsapp->templateApi()->send($template, $to, [], $language);

            return $this->ok("Template \"{$template}\" enviado para {$to}. Responda a mensagem no celular para testar o webhook.");
        });
    }

    /**
     * Re-check everything against Meta. Each check is independent: one failure
     * does not hide the others.
     */
    public function diagnose(Request $request, EmbeddedSignup $signup): RedirectResponse
    {
        $this->guardUiToken($request);

        $checks = [];

        $check = function (string $label, callable $probe) use (&$checks): void {
            try {
                [$ok, $detail] = $probe();
            } catch (Throwable $e) {
                [$ok, $detail] = [false, $e->getMessage()];
            }

            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };

        $check('Credenciais do app (App ID + Secret)', function () {
            $app = $this->appClient()->app();

            return [true, 'App "'.($app['name'] ?? $app['id']).'"'];
        });

        $check('Webhook registrado na Meta', function () {
            $subscription = $this->appClient()->webhookSubscription();

            if ($subscription === null) {
                return [false, 'Nenhum webhook de WhatsApp registrado no app.'];
            }

            $fields = array_map(fn ($f) => is_array($f) ? ($f['name'] ?? '') : $f, (array) ($subscription['fields'] ?? []));
            $url = (string) ($subscription['callback_url'] ?? '');
            $ok = ($subscription['active'] ?? false) && in_array('messages', $fields, true);

            if ($ok && $this->callbackUrl() !== null && $url !== $this->callbackUrl()) {
                return [false, "Ativo, mas aponta para {$url} (esperado {$this->callbackUrl()})."];
            }

            return [$ok, $ok ? "Ativo em {$url}" : 'Inativo ou sem o campo "messages".'];
        });

        $check('Token do número padrão', function () {
            $token = (string) config('whatsapp-cloud.default.access_token');

            if ($token === '') {
                return [false, 'Nenhum número padrão definido.'];
            }

            $data = $this->appClient()->debugToken($token);
            $scopes = (array) ($data['scopes'] ?? []);

            if (! ($data['is_valid'] ?? false)) {
                return [false, 'Token inválido ou revogado — reconecte o número.'];
            }

            if (! in_array('whatsapp_business_messaging', $scopes, true)) {
                return [false, 'Token sem a permissão whatsapp_business_messaging.'];
            }

            $expires = (int) ($data['expires_at'] ?? 0);

            return [true, $expires > 0 ? 'Válido até '.date('d/m/Y', $expires) : 'Válido, não expira'];
        });

        $check('Número padrão na WABA', function () use ($signup) {
            $phone = (string) config('whatsapp-cloud.default.phone_number_id');
            $waba = (string) config('whatsapp-cloud.default.waba_id');

            if ($phone === '' || $waba === '') {
                return [false, 'Defina o Phone Number ID e o WABA ID do número padrão.'];
            }

            $data = $signup->findPhoneNumber($waba, $phone, (string) config('whatsapp-cloud.default.access_token'));

            return [true, trim(($data['display_phone_number'] ?? $phone).' · '.($data['verified_name'] ?? '').' · qualidade '.($data['quality_rating'] ?? '?'))];
        });

        $check('Embedded Signup', function () {
            return filled(config('whatsapp-cloud.embedded_signup.config_id'))
                ? [true, 'config_id '.config('whatsapp-cloud.embedded_signup.config_id')]
                : [false, 'Sem config_id — conectar número pelo Facebook fica desativado.'];
        });

        return back()->with(self::DIAGNOSTICS_KEY, $checks);
    }

    /**
     * Download the effective configuration (panel + .env) as JSON. It holds
     * SECRETS — the panel says so before the download.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->guardUiToken($request);

        $settings = $this->settings->effective();
        unset($settings['webhook_subscribed_at']);

        $json = (string) json_encode([
            'whatsapp-cloud' => $settings,
            'exported_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(fn () => print ($json), 'whatsapp-cloud.json', [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Load a JSON exported by {@see export()} (or a flat {key: value} object).
     * Unknown keys are rejected rather than silently dropped.
     */
    public function import(Request $request): RedirectResponse
    {
        $this->guardUiToken($request);

        $raw = $request->hasFile('file')
            ? (string) file_get_contents((string) $request->file('file')?->getRealPath())
            : (string) $request->input('json', '');

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return back()->withErrors(['form' => 'JSON inválido.']);
        }

        $values = is_array($data['whatsapp-cloud'] ?? null) ? $data['whatsapp-cloud'] : $data;
        unset($values['exported_at'], $values['webhook_subscribed_at']);

        $unknown = array_diff(array_keys($values), array_keys(SettingsStore::KEYS));

        if ($unknown !== []) {
            return back()->withErrors(['form' => 'Chaves desconhecidas no JSON: '.implode(', ', $unknown).'.']);
        }

        $values = array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $values);

        try {
            $this->settings->put($values);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return $this->ok(count($values).' configurações importadas. Rode a validação para conferir.');
    }

    private function makeDefault(Model&WhatsAppCredentials $number): void
    {
        $this->settings->put([
            'default_phone_number_id' => $number->phoneNumberId(),
            'default_waba_id' => $number->wabaId(),
            'default_access_token' => $number->accessToken(),
        ]);
    }

    /**
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
        }
    }

    private function appClient(): MetaAppClient
    {
        $appId = (string) config('whatsapp-cloud.app_id');
        $secret = (string) config('whatsapp-cloud.app_secret');

        if ($appId === '' || $secret === '') {
            throw new WhatsAppNotConfiguredException('Configure o App ID e o App Secret primeiro (passo 1).');
        }

        return new MetaAppClient((string) config('whatsapp-cloud.graph_version', 'v21.0'), $appId, $secret);
    }

    /**
     * Every setting with its source; secrets reduced to a hint.
     *
     * @return array<string, array{value: string|null, source: string|null, secret: bool}>
     */
    private function maskedSettings(): array
    {
        $sources = $this->settings->sources();
        $out = [];

        foreach (SettingsStore::KEYS as $key => $path) {
            $value = config($path);
            $value = filled($value) && is_scalar($value) ? (string) $value : null;
            $secret = in_array($key, SettingsStore::SECRETS, true);

            $out[$key] = [
                'value' => $secret && $value !== null ? '••••'.substr($value, -4) : $value,
                'source' => $sources[$key],
                'secret' => $secret,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function numbers(): array
    {
        try {
            return $this->model()::query()->latest('id')->limit(50)->get()
                ->map(fn (Model $n) => [
                    'id' => $n->getKey(),
                    'key' => $n->getAttribute('key'),
                    'phone_number_id' => (string) $n->getAttribute('phone_number_id'),
                    'display_phone_number' => $n->getAttribute('display_phone_number'),
                    'verified_name' => $n->getAttribute('verified_name'),
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastInbound(): ?array
    {
        try {
            /** @var class-string<WhatsAppInboundMessage> $model */
            $model = config('whatsapp-cloud.inbound.model', WhatsAppInboundMessage::class);
            $message = $model::query()->latest('id')->first();
        } catch (Throwable) {
            return null;
        }

        return $message === null ? null : [
            'from' => $message->wa_id,
            'name' => $message->contact_name,
            'text' => $message->text ?? "({$message->type})",
            'at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function callbackUrl(): ?string
    {
        $name = config('whatsapp-cloud.webhook.name', 'whatsapp.cloud').'.verify';

        return Route::has($name) ? route($name) : null;
    }

    private function routeIfExists(string $name): ?string
    {
        return Route::has($name) ? route($name) : null;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function ok(string $message, array $warnings = []): RedirectResponse
    {
        return back()
            ->with(self::NOTICE_KEY, ['message' => $message, 'warnings' => $warnings])
            ->with('flash', ['toast' => ['type' => 'success', 'message' => $message], 'warnings' => $warnings]);
    }

    /**
     * @return class-string<Model&WhatsAppCredentials>
     */
    private function model(): string
    {
        return config('whatsapp-cloud.model', WhatsAppNumber::class);
    }

    private function component(): string
    {
        return (string) config('whatsapp-cloud.setup.component', 'WhatsAppCloud/Setup/Index');
    }

    private function routeName(string $action): string
    {
        return (string) config('whatsapp-cloud.setup.name', 'whatsapp.cloud.setup').'.'.$action;
    }
}

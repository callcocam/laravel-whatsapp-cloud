<?php

namespace Callcocam\WhatsAppCloud\Http\Controllers;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Exceptions\WhatsAppException;
use Callcocam\WhatsAppCloud\Http\Controllers\Concerns\GuardsPanelUiToken;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Callcocam\WhatsAppCloud\Onboarding\EmbeddedSignup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The "connected numbers" page: lists the credentials model's rows and connects
 * new numbers through Meta's Embedded Signup. A thin HTTP adapter over
 * {@see EmbeddedSignup} — same error contract as the template panel
 * (`errors.meta` for Meta failures, `errors.form` for local rejections).
 */
class NumbersPanelController
{
    use GuardsPanelUiToken;

    private const WARNINGS_KEY = 'whatsapp_cloud_numbers_warnings';

    public function index(Request $request): InertiaResponse
    {
        $this->guardUiToken($request);

        $numbers = [];
        $loadError = null;

        try {
            $numbers = $this->model()::query()->latest('id')->get()
                ->map(fn (Model $number) => $this->present($number))
                ->values()
                ->all();
        } catch (QueryException) {
            $loadError = 'Tabela whatsapp_numbers indisponível — publique e rode as migrations '
                .'(whatsapp-cloud-migrations e whatsapp-cloud-embedded-signup-migrations).';
        }

        return Inertia::render($this->component(), [
            'numbers' => $numbers,
            'signup' => [
                'app_id' => config('whatsapp-cloud.app_id'),
                'config_id' => config('whatsapp-cloud.embedded_signup.config_id'),
                'graph_version' => (string) config('whatsapp-cloud.graph_version', 'v21.0'),
                'ready' => filled(config('whatsapp-cloud.app_id'))
                    && filled(config('whatsapp-cloud.app_secret'))
                    && filled(config('whatsapp-cloud.embedded_signup.config_id')),
                'pin_configured' => filled(config('whatsapp-cloud.embedded_signup.register_pin')),
            ],
            'loadError' => $loadError,
            // Non-fatal problems from the last action, flashed by ok(). Read from
            // the session here so the page shows them even when the host app
            // does not share `flash` through its Inertia middleware.
            'warnings' => array_values((array) $request->session()->get(self::WARNINGS_KEY, [])),
            'panelUrl' => route($this->routeName('index')),
            'setupUrl' => Route::has($setup = config('whatsapp-cloud.setup.name', 'whatsapp.cloud.setup').'.index') ? route($setup) : null,
        ]);
    }

    /**
     * Finish an Embedded Signup: the browser posts the `code` from FB.login and
     * the WABA / phone number ids from the WA_EMBEDDED_SIGNUP message.
     */
    public function store(Request $request, EmbeddedSignup $signup): RedirectResponse
    {
        $this->guardUiToken($request);

        $code = trim((string) $request->input('code', ''));
        $wabaId = trim((string) $request->input('waba_id', ''));
        $phoneNumberId = trim((string) $request->input('phone_number_id', ''));
        $businessId = trim((string) $request->input('business_id', '')) ?: null;
        $pin = trim((string) $request->input('pin', '')) ?: config('whatsapp-cloud.embedded_signup.register_pin');

        if ($code === '' || ! ctype_digit($wabaId) || ! ctype_digit($phoneNumberId)) {
            return back()->withErrors(['form' => 'Cadastro incompleto: faltou o código ou o número/WABA escolhidos na Meta.']);
        }

        if ($businessId !== null && ! ctype_digit($businessId)) {
            $businessId = null;
        }

        if (filled($pin) && ! preg_match('/^\d{6}$/', (string) $pin)) {
            return back()->withErrors(['form' => 'O PIN precisa ter exatamente 6 dígitos.']);
        }

        return $this->run(function () use ($signup, $code, $wabaId, $phoneNumberId, $businessId, $pin): RedirectResponse {
            $result = $signup->connect($code, $wabaId, $phoneNumberId, $businessId, filled($pin) ? (string) $pin : null);

            $label = $result['number']->getAttribute('display_phone_number') ?: $phoneNumberId;

            return $this->ok("Número {$label} conectado.", $result['warnings']);
        });
    }

    /**
     * Re-read the number's profile from Meta and re-subscribe the webhooks.
     */
    public function refresh(Request $request, string $number, EmbeddedSignup $signup): RedirectResponse
    {
        $this->guardUiToken($request);

        $record = $this->find($number);

        return $this->run(fn (): RedirectResponse => $this->ok('Número atualizado.', $signup->refresh($record)));
    }

    public function destroy(Request $request, string $number, EmbeddedSignup $signup): RedirectResponse
    {
        $this->guardUiToken($request);

        $record = $this->find($number);
        $signup->disconnect($record);

        return $this->ok('Número desconectado.');
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

    /**
     * Public view of a number — never the access token.
     *
     * @return array<string, mixed>
     */
    private function present(Model $number): array
    {
        $expiresAt = $number->getAttribute('token_expires_at');
        $connectedAt = $number->getAttribute('connected_at');

        return [
            'id' => $number->getKey(),
            'key' => $number->getAttribute('key'),
            'display_phone_number' => $number->getAttribute('display_phone_number'),
            'verified_name' => $number->getAttribute('verified_name'),
            'phone_number_id' => $number->getAttribute('phone_number_id'),
            'waba_id' => $number->getAttribute('waba_id'),
            'quality_rating' => $number->getAttribute('quality_rating'),
            'token_expires_at' => $expiresAt instanceof Carbon ? $expiresAt->toIso8601String() : null,
            'connected_at' => $connectedAt instanceof Carbon ? $connectedAt->toIso8601String() : null,
        ];
    }

    private function find(string $id): Model&WhatsAppCredentials
    {
        $record = $this->model()::query()->find($id);

        abort_unless($record instanceof WhatsAppCredentials, 404);

        return $record;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function ok(string $message, array $warnings = []): RedirectResponse
    {
        return back()
            ->with(self::WARNINGS_KEY, $warnings)
            ->with('flash', [
                'toast' => ['type' => 'success', 'message' => $message],
                'warnings' => $warnings,
            ]);
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
        return (string) config('whatsapp-cloud.embedded_signup.component', 'WhatsAppCloud/Numbers/Index');
    }

    private function routeName(string $action): string
    {
        return (string) config('whatsapp-cloud.embedded_signup.name', 'whatsapp.cloud.numbers').'.'.$action;
    }
}

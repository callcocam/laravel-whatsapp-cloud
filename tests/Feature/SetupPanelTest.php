<?php

use Callcocam\WhatsAppCloud\Models\WhatsAppInboundMessage;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Callcocam\WhatsAppCloud\Models\WhatsAppSetting;
use Callcocam\WhatsAppCloud\Settings\SettingsStore;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(new GenericUser(['id' => 1]));
});

function setupUrl(string $path = ''): string
{
    return 'whatsapp/cloud/setup'.$path;
}

function store(): SettingsStore
{
    return app(SettingsStore::class);
}

it('renders the wizard without leaking secrets', function () {
    store()->put(['app_id' => '111', 'app_secret' => 'super-secret-value']);

    $this->get(setupUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('WhatsAppCloud/Setup/Index')
            ->where('settings.app_id.value', '111')
            ->where('settings.app_id.source', 'panel')
            ->where('settings.app_secret.value', '••••alue')
            ->where('settings.verify_token.source', 'env')
            ->where('steps.app', true)
            ->where('steps.webhook', false)
            ->where('webhook.callback_url', url('webhooks/whatsapp/cloud'))
        );
});

it('validates the app against Meta before saving it, encrypted', function () {
    Http::fake(['graph.facebook.com/v22.0/111*' => Http::response(['id' => '111', 'name' => 'noazul'])]);

    $this->post(setupUrl('/app'), ['app_id' => '111', 'app_secret' => 'sec', 'graph_version' => 'v22.0'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('whatsapp_cloud_setup_notice', fn ($n) => str_contains($n['message'], 'noazul'));

    Http::assertSent(fn (Request $r) => $r['access_token'] === '111|sec');

    expect(config('whatsapp-cloud.app_id'))->toBe('111')
        ->and(config('whatsapp-cloud.app_secret'))->toBe('sec')
        ->and(config('whatsapp-cloud.graph_version'))->toBe('v22.0');

    $raw = WhatsAppSetting::query()->where('key', 'app_secret')->sole()->getRawOriginal('value');
    expect($raw)->not->toContain('sec');
});

it('does not save app credentials Meta rejects', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400)]);

    $this->post(setupUrl('/app'), ['app_id' => '111', 'app_secret' => 'wrong'])
        ->assertSessionHasErrors('meta');

    expect(WhatsAppSetting::query()->count())->toBe(0);
});

it('registers the webhook by API with a generated verify token that the webhook then accepts', function () {
    config(['whatsapp-cloud.verify_token' => null]);
    store()->put(['app_id' => '111', 'app_secret' => 'sec']);
    Http::fake(['graph.facebook.com/v21.0/111/subscriptions' => Http::response(['success' => true])]);

    $this->post(setupUrl('/webhook'), ['callback_url' => 'https://app.test/webhooks/whatsapp/cloud'])
        ->assertSessionHasNoErrors();

    $token = config('whatsapp-cloud.verify_token');
    expect($token)->toBeString()->toHaveLength(40)
        ->and(config('whatsapp-cloud.setup.webhook_subscribed_at'))->not->toBeNull();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r['object'] === 'whatsapp_business_account'
        && $r['callback_url'] === 'https://app.test/webhooks/whatsapp/cloud'
        && $r['verify_token'] === $token
        && $r['fields'] === 'messages'
        && $r['access_token'] === '111|sec');

    // The handshake Meta performs during that call succeeds with the stored token.
    $this->get('webhooks/whatsapp/cloud?hub_mode=subscribe&hub_verify_token='.$token.'&hub_challenge=42')
        ->assertOk()
        ->assertSee('42');
});

it('refuses a non-HTTPS callback', function () {
    store()->put(['app_id' => '111', 'app_secret' => 'sec']);
    Http::fake();

    $this->post(setupUrl('/webhook'), ['callback_url' => 'http://localhost/webhooks'])
        ->assertSessionHasErrors('form');

    Http::assertNothingSent();
});

it('saves the Embedded Signup config_id and PIN', function () {
    $this->post(setupUrl('/signup'), ['config_id' => '1634670798041792', 'register_pin' => '123456'])
        ->assertSessionHasNoErrors();

    expect(config('whatsapp-cloud.embedded_signup.config_id'))->toBe('1634670798041792')
        ->and(config('whatsapp-cloud.embedded_signup.register_pin'))->toBe('123456');

    $this->post(setupUrl('/signup'), ['config_id' => 'abc'])->assertSessionHasErrors('form');
});

it('saves a manual number after checking it against the WABA, and makes it default', function () {
    Http::fake([
        'graph.facebook.com/v21.0/555/phone_numbers*' => Http::response(['data' => [
            ['id' => '777', 'display_phone_number' => '+55 48 3333-0000', 'verified_name' => 'Contas', 'quality_rating' => 'GREEN'],
        ]]),
        'graph.facebook.com/v21.0/555/subscribed_apps' => Http::response(['success' => true]),
    ]);

    $this->post(setupUrl('/number'), [
        'phone_number_id' => '777', 'waba_id' => '555', 'access_token' => 'tok', 'key' => 'contas', 'make_default' => true,
    ])->assertSessionHasNoErrors();

    $number = WhatsAppNumber::query()->sole();
    expect($number->key)->toBe('contas')
        ->and($number->verified_name)->toBe('Contas')
        ->and(config('whatsapp-cloud.default.phone_number_id'))->toBe('777')
        ->and(config('whatsapp-cloud.default.waba_id'))->toBe('555')
        ->and(config('whatsapp-cloud.default.access_token'))->toBe('tok');
});

it('rejects a manual number that is not in the WABA', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

    $this->post(setupUrl('/number'), ['phone_number_id' => '777', 'waba_id' => '555', 'access_token' => 'tok'])
        ->assertSessionHasErrors('meta');

    expect(WhatsAppNumber::query()->count())->toBe(0);
});

it('makes a stored number the default', function () {
    $number = WhatsAppNumber::query()->create(['phone_number_id' => '888', 'waba_id' => '444', 'cloud_access_token' => 'tk']);

    $this->post(setupUrl("/default/{$number->id}"))->assertSessionHasNoErrors();

    expect(config('whatsapp-cloud.default.phone_number_id'))->toBe('888')
        ->and(config('whatsapp-cloud.default.access_token'))->toBe('tk');
});

it('sends a test template from the default number', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);

    $this->post(setupUrl('/test'), ['to' => '+55 (48) 99999-0000'])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/111222333/messages')
        && $r['to'] === '5548999990000'
        && $r['template']['name'] === 'hello_world'
        && $r['template']['language']['code'] === 'en_US');
});

it('shows the last inbound message as proof the webhook works', function () {
    WhatsAppInboundMessage::query()->create([
        'wamid' => 'wamid.in', 'wa_id' => '5548999990000', 'type' => 'text', 'text' => 'oi', 'status' => 'received',
    ]);

    $this->get(setupUrl())->assertInertia(fn (Assert $page) => $page
        ->where('steps.test', true)
        ->where('lastInbound.text', 'oi'));
});

it('diagnoses every piece independently', function () {
    store()->put(['app_id' => '111', 'app_secret' => 'sec']);
    Http::fake([
        'graph.facebook.com/v21.0/111/subscriptions*' => Http::response(['data' => [[
            'object' => 'whatsapp_business_account', 'active' => true,
            'callback_url' => url('webhooks/whatsapp/cloud'), 'fields' => [['name' => 'messages', 'version' => 'v21.0']],
        ]]]),
        'graph.facebook.com/v21.0/111*' => Http::response(['id' => '111', 'name' => 'noazul']),
        'graph.facebook.com/v21.0/debug_token*' => Http::response(['data' => [
            'is_valid' => true, 'expires_at' => 0, 'scopes' => ['whatsapp_business_messaging', 'whatsapp_business_management'],
        ]]),
        'graph.facebook.com/v21.0/999888777/phone_numbers*' => Http::response(['error' => ['message' => 'Unsupported get request.', 'code' => 100]], 400),
    ]);

    $this->post(setupUrl('/diagnose'))
        ->assertSessionHas('whatsapp_cloud_setup_diagnostics', function (array $checks) {
            $byLabel = collect($checks)->keyBy('label');

            return $byLabel['Credenciais do app (App ID + Secret)']['ok'] === true
                && $byLabel['Webhook registrado na Meta']['ok'] === true
                && $byLabel['Token do número padrão']['ok'] === true
                && $byLabel['Número padrão na WABA']['ok'] === false
                && $byLabel['Embedded Signup']['ok'] === false;
        });
});

it('exports the effective configuration and imports it back', function () {
    store()->put(['app_id' => '111', 'app_secret' => 'sec', 'embedded_signup_config_id' => '999']);

    $response = $this->get(setupUrl('/export'))->assertOk();
    $json = $response->streamedContent();
    $data = json_decode($json, true);

    expect($data['whatsapp-cloud'])->toMatchArray([
        'app_id' => '111',
        'app_secret' => 'sec',
        'embedded_signup_config_id' => '999',
        'verify_token' => 'test-verify-token',
    ])->not->toHaveKey('webhook_subscribed_at');

    WhatsAppSetting::query()->delete();
    store()->put([]);

    $this->post(setupUrl('/import'), ['file' => UploadedFile::fake()->createWithContent('whatsapp-cloud.json', $json)])
        ->assertSessionHasNoErrors();

    expect(store()->get('app_secret'))->toBe('sec')
        ->and(store()->get('embedded_signup_config_id'))->toBe('999');
});

it('rejects an import with unknown keys', function () {
    $this->post(setupUrl('/import'), ['json' => '{"app_id": "1", "hacker": "x"}'])
        ->assertSessionHasErrors('form');

    expect(WhatsAppSetting::query()->count())->toBe(0);
});

<?php

use Callcocam\WhatsAppCloud\Events\WhatsAppNumberConnected;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Callcocam\WhatsAppCloud\Settings\SettingsStore;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(new GenericUser(['id' => 1]));

    config([
        'whatsapp-cloud.app_id' => 'app-123',
        'whatsapp-cloud.embedded_signup.config_id' => 'cfg-456',
    ]);
});

function numbersUrl(string $path = ''): string
{
    return 'whatsapp/cloud/numbers'.$path;
}

/**
 * Meta answering a full, successful signup.
 *
 * @param  array<string, mixed>  $overrides  url pattern => response
 */
function fakeMetaSignup(array $overrides = []): void
{
    Http::fake($overrides + [
        'graph.facebook.com/v21.0/oauth/access_token*' => Http::response([
            'access_token' => 'business-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
        'graph.facebook.com/v21.0/555/phone_numbers*' => Http::response(['data' => [
            ['id' => '777', 'display_phone_number' => '+55 48 99999-0000', 'verified_name' => 'Coordena', 'quality_rating' => 'GREEN'],
        ]]),
        'graph.facebook.com/v21.0/555/subscribed_apps' => Http::response(['success' => true]),
        'graph.facebook.com/v21.0/777/register' => Http::response(['success' => true]),
    ]);
}

it('renders the numbers page with the public signup config', function () {
    WhatsAppNumber::query()->create([
        'phone_number_id' => '777',
        'waba_id' => '555',
        'cloud_access_token' => 'secret-token',
        'display_phone_number' => '+55 48 99999-0000',
    ]);

    $this->get(numbersUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('WhatsAppCloud/Numbers/Index')
            ->has('numbers', 1)
            ->where('numbers.0.phone_number_id', '777')
            ->missing('numbers.0.cloud_access_token')
            ->where('signup.app_id', 'app-123')
            ->where('signup.config_id', 'cfg-456')
            ->where('signup.ready', true)
        );
});

it('is not ready without a config_id', function () {
    config(['whatsapp-cloud.embedded_signup.config_id' => null]);

    $this->get(numbersUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('signup.ready', false));
});

it('connects a number: exchanges the code, checks the WABA, subscribes, registers and stores', function () {
    Event::fake([WhatsAppNumberConnected::class]);
    fakeMetaSignup();

    $this->post(numbersUrl(), [
        'code' => 'the-code',
        'waba_id' => '555',
        'phone_number_id' => '777',
        'business_id' => '999',
        'pin' => '123456',
    ])->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth/access_token')
        && $r['client_id'] === 'app-123'
        && $r['client_secret'] === 'test-app-secret'
        && $r['code'] === 'the-code');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/555/subscribed_apps')
        && $r->hasHeader('Authorization', 'Bearer business-token'));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/777/register')
        && $r['pin'] === '123456'
        && $r['messaging_product'] === 'whatsapp');

    $number = WhatsAppNumber::query()->where('phone_number_id', '777')->sole();

    expect($number->waba_id)->toBe('555')
        ->and($number->business_id)->toBe('999')
        ->and($number->accessToken())->toBe('business-token')
        ->and($number->display_phone_number)->toBe('+55 48 99999-0000')
        ->and($number->verified_name)->toBe('Coordena')
        ->and($number->quality_rating)->toBe('GREEN')
        ->and($number->app_id)->toBe('app-123')
        ->and($number->token_expires_at?->isFuture())->toBeTrue()
        ->and($number->connected_at)->not->toBeNull();

    // Stored encrypted, never in clear text.
    expect($number->getRawOriginal('cloud_access_token'))->not->toBe('business-token');

    Event::assertDispatched(WhatsAppNumberConnected::class, fn ($e) => $e->number->is($number) && $e->warnings === []);
});

it('skips registration when no PIN is given', function () {
    fakeMetaSignup();

    $this->post(numbersUrl(), ['code' => 'c', 'waba_id' => '555', 'phone_number_id' => '777'])
        ->assertSessionHasNoErrors();

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/register'));
    expect(WhatsAppNumber::query()->count())->toBe(1);
});

it('falls back to the configured register PIN', function () {
    config(['whatsapp-cloud.embedded_signup.register_pin' => '654321']);
    fakeMetaSignup();

    $this->post(numbersUrl(), ['code' => 'c', 'waba_id' => '555', 'phone_number_id' => '777'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/777/register') && $r['pin'] === '654321');
});

it('updates the same row when a number is reconnected', function () {
    WhatsAppNumber::query()->create(['phone_number_id' => '777', 'key' => 'team-a', 'cloud_access_token' => 'old']);
    fakeMetaSignup();

    $this->post(numbersUrl(), ['code' => 'c', 'waba_id' => '555', 'phone_number_id' => '777'])
        ->assertSessionHasNoErrors();

    $number = WhatsAppNumber::query()->sole();
    expect($number->key)->toBe('team-a')
        ->and($number->accessToken())->toBe('business-token');
});

it('refuses a phone number that does not belong to the WABA', function () {
    fakeMetaSignup([
        'graph.facebook.com/v21.0/555/phone_numbers*' => Http::response(['data' => [['id' => '888']]]),
    ]);

    $this->post(numbersUrl(), ['code' => 'c', 'waba_id' => '555', 'phone_number_id' => '777'])
        ->assertSessionHasErrors('meta');

    expect(WhatsAppNumber::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'subscribed_apps'));
});

it('surfaces a rejected code as a Meta error', function () {
    fakeMetaSignup([
        'graph.facebook.com/v21.0/oauth/access_token*' => Http::response(
            ['error' => ['message' => 'Invalid verification code format.', 'code' => 100]],
            400,
        ),
    ]);

    $this->post(numbersUrl(), ['code' => 'bad', 'waba_id' => '555', 'phone_number_id' => '777'])
        ->assertSessionHasErrors(['meta' => 'WhatsApp Cloud API error: Invalid verification code format. (code 100)']);

    expect(WhatsAppNumber::query()->count())->toBe(0);
});

it('still saves the number when subscription and registration fail, with warnings', function () {
    fakeMetaSignup([
        'graph.facebook.com/v21.0/555/subscribed_apps' => Http::response(['error' => ['message' => 'Nope', 'code' => 200]], 403),
        'graph.facebook.com/v21.0/777/register' => Http::response(['error' => ['message' => 'Wrong PIN', 'code' => 133005]], 400),
    ]);

    $this->post(numbersUrl(), ['code' => 'c', 'waba_id' => '555', 'phone_number_id' => '777', 'pin' => '111111'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('whatsapp_cloud_numbers_warnings', fn (array $w) => count($w) === 2);

    expect(WhatsAppNumber::query()->count())->toBe(1);
});

it('validates the signup payload before calling Meta', function (array $payload) {
    Http::fake();

    $this->post(numbersUrl(), $payload)->assertSessionHasErrors('form');

    Http::assertNothingSent();
})->with([
    'no code' => [['waba_id' => '555', 'phone_number_id' => '777']],
    'non-numeric waba' => [['code' => 'c', 'waba_id' => 'abc', 'phone_number_id' => '777']],
    'bad pin' => [['code' => 'c', 'waba_id' => '555', 'phone_number_id' => '777', 'pin' => '12']],
]);

it('reports missing app credentials instead of calling Meta', function () {
    config(['whatsapp-cloud.app_secret' => null]);
    Http::fake();

    $this->post(numbersUrl(), ['code' => 'c', 'waba_id' => '555', 'phone_number_id' => '777'])
        ->assertSessionHasErrors('meta');

    Http::assertNothingSent();
});

it('refreshes a number from Meta', function () {
    $number = WhatsAppNumber::query()->create([
        'phone_number_id' => '777', 'waba_id' => '555', 'cloud_access_token' => 'business-token',
    ]);
    fakeMetaSignup([
        'graph.facebook.com/v21.0/555/phone_numbers*' => Http::response(['data' => [
            ['id' => '777', 'display_phone_number' => '+55 48 1', 'verified_name' => 'Novo', 'quality_rating' => 'YELLOW'],
        ]]),
    ]);

    $this->post(numbersUrl("/{$number->id}/refresh"))->assertSessionHasNoErrors();

    expect($number->fresh()->quality_rating)->toBe('YELLOW')
        ->and($number->fresh()->verified_name)->toBe('Novo');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/555/subscribed_apps'));
});

it('disconnects a number even when Meta refuses the unsubscribe', function () {
    $number = WhatsAppNumber::query()->create([
        'phone_number_id' => '777', 'waba_id' => '555', 'cloud_access_token' => 'revoked',
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token', 'code' => 190]], 401)]);

    $this->delete(numbersUrl("/{$number->id}"))->assertRedirect();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/555/subscribed_apps'));
    expect(WhatsAppNumber::query()->count())->toBe(0);
});

it('404s an unknown number', function () {
    $this->post(numbersUrl('/999/refresh'))->assertNotFound();
});

it('registers the page under the default prefix', function () {
    expect(route('whatsapp.cloud.numbers.index', absolute: false))->toBe('/whatsapp/cloud/numbers');
});

it('keeps the WABA subscription while another number still uses it', function () {
    $a = WhatsAppNumber::query()->create(['phone_number_id' => '777', 'waba_id' => '555', 'cloud_access_token' => 't']);
    WhatsAppNumber::query()->create(['phone_number_id' => '778', 'waba_id' => '555', 'cloud_access_token' => 't']);
    Http::fake();

    $this->delete(numbersUrl("/{$a->id}"))->assertRedirect();

    Http::assertNothingSent();
    expect(WhatsAppNumber::query()->pluck('phone_number_id')->all())->toBe(['778']);
});

it('clears the default sender when the default number is disconnected', function () {
    $number = WhatsAppNumber::query()->create(['phone_number_id' => '777', 'waba_id' => '555', 'cloud_access_token' => 'tok']);
    $other = WhatsAppNumber::query()->create(['phone_number_id' => '888', 'waba_id' => '444', 'cloud_access_token' => 'tk2']);
    $settings = app(SettingsStore::class);
    $settings->put(['default_phone_number_id' => '777', 'default_waba_id' => '555', 'default_access_token' => 'tok']);
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    // Disconnecting another number leaves the default alone...
    $this->delete(numbersUrl("/{$other->id}"))->assertRedirect();
    expect($settings->get('default_phone_number_id'))->toBe('777');

    // ...disconnecting the default one hands the sender back to the .env.
    $this->delete(numbersUrl("/{$number->id}"))->assertRedirect();
    expect($settings->get('default_phone_number_id'))->toBeNull()
        ->and($settings->get('default_access_token'))->toBeNull()
        ->and(config('whatsapp-cloud.default.phone_number_id'))->toBe('111222333');
});

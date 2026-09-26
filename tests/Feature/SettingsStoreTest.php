<?php

use Callcocam\WhatsAppCloud\Models\WhatsAppSetting;
use Callcocam\WhatsAppCloud\Settings\SettingsStore;
use Illuminate\Support\Facades\Schema;

it('overrides the .env value while stored, and hands it back when removed', function () {
    $store = app(SettingsStore::class);

    expect(config('whatsapp-cloud.app_secret'))->toBe('test-app-secret');

    $store->put(['app_secret' => 'from-panel']);
    expect(config('whatsapp-cloud.app_secret'))->toBe('from-panel')
        ->and($store->sources()['app_secret'])->toBe('panel');

    $store->put(['app_secret' => null]);
    expect(config('whatsapp-cloud.app_secret'))->toBe('test-app-secret')
        ->and($store->sources()['app_secret'])->toBe('env')
        ->and(WhatsAppSetting::query()->count())->toBe(0);
});

it('applies stored values on a fresh boot', function () {
    WhatsAppSetting::query()->create(['key' => 'embedded_signup_config_id', 'value' => '42']);

    $fresh = new SettingsStore(config());
    $fresh->apply();

    expect(config('whatsapp-cloud.embedded_signup.config_id'))->toBe('42');
});

it('leaves the config alone when the table does not exist', function () {
    Schema::drop('whatsapp_settings');

    $fresh = new SettingsStore(config());
    $fresh->apply();

    expect($fresh->all())->toBe([])
        ->and(config('whatsapp-cloud.app_secret'))->toBe('test-app-secret');
});

it('refuses unknown keys', function () {
    app(SettingsStore::class)->put(['nope' => 'x']);
})->throws(InvalidArgumentException::class);

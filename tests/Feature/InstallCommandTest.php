<?php

use Illuminate\Support\Facades\File;

/**
 * `whatsapp:install` publishes into the APP's paths, and the publish map is frozen
 * at boot — so it cannot be redirected at a temp dir the way ScaffoldPanelTest
 * redirects the scaffold command.
 *
 * Under Testbench the "app" is the skeleton inside vendor/. Left alone, every run
 * of this suite drops another date-stamped migration in there, and after enough
 * runs `vendor/bin/testbench migrate` dies with a duplicate-table error. So we
 * clean up what we published.
 */
afterEach(function () {
    foreach ((array) File::glob(database_path('migrations/*_create_whatsapp_*.php')) as $file) {
        File::delete($file);
    }

    File::delete(config_path('whatsapp-cloud.php'));
});

it('runs the install command and prints the checklist', function () {
    $this->artisan('whatsapp:install')
        ->assertExitCode(0)
        ->expectsOutputToContain('WhatsApp Cloud installed');

    expect(File::glob(database_path('migrations/*_create_whatsapp_numbers_table.php')))->toHaveCount(1)
        ->and(is_file(config_path('whatsapp-cloud.php')))->toBeTrue();
});

it('points the installer at the sandbox', function () {
    $this->artisan('whatsapp:install')->expectsOutputToContain('WHATSAPP_CLOUD_DRIVER=sandbox');
});

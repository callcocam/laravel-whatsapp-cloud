<?php

use Illuminate\Support\Facades\File;

/**
 * `resource_path()` derives from the app base path, so we point the base path at
 * a throwaway dir and assert the command writes under `<base>/resources/js/...`.
 */
it('scaffolds the native panel pages into the host resources, dropping .stub', function () {
    $tmp = sys_get_temp_dir().'/wa-scaffold-'.uniqid();
    $this->app->setBasePath($tmp);

    $this->artisan('whatsapp:panel:scaffold', ['--force' => true])->assertExitCode(0);

    $base = $tmp.'/resources/js/pages/WhatsAppCloud/Templates';

    // The pages land in the host tree with the `.stub` suffix removed.
    expect(is_file($base.'/Index.vue'))->toBeTrue();
    expect(is_file($base.'/partials/TemplateFormModal.vue'))->toBeTrue();
    expect(is_file($base.'/partials/StatusBadge.vue'))->toBeTrue();
    // format.js ships without a suffix (pure JS) and is copied as-is.
    expect(is_file($base.'/partials/format.js'))->toBeTrue();
    // No leftover .stub files.
    expect(is_file($base.'/Index.vue.stub'))->toBeFalse();

    File::deleteDirectory($tmp);
});

it('skips existing files without --force', function () {
    $tmp = sys_get_temp_dir().'/wa-scaffold-'.uniqid();
    $this->app->setBasePath($tmp);

    $target = $tmp.'/resources/js/pages/WhatsAppCloud/Templates';
    File::ensureDirectoryExists($target);
    File::put($target.'/Index.vue', 'CUSTOM');

    $this->artisan('whatsapp:panel:scaffold')->assertExitCode(0);

    // The existing file is left untouched.
    expect(File::get($target.'/Index.vue'))->toBe('CUSTOM');

    File::deleteDirectory($tmp);
});

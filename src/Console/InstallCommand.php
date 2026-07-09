<?php

namespace Callcocam\WhatsAppCloud\Console;

use Illuminate\Console\Command;
use Inertia\Inertia;

class InstallCommand extends Command
{
    protected $signature = 'whatsapp:install {--force : Overwrite any published files}';

    protected $description = 'Publish the WhatsApp Cloud config + migration and print a setup checklist';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->components->task('Publishing config', fn () => $this->callSilently('vendor:publish', [
            '--tag' => 'whatsapp-cloud-config',
            '--force' => $force,
        ]) === self::SUCCESS);

        $this->components->task('Publishing migration', fn () => $this->callSilently('vendor:publish', [
            '--tag' => 'whatsapp-cloud-migrations',
            '--force' => $force,
        ]) === self::SUCCESS);

        $hasInertia = class_exists(Inertia::class);

        if ($hasInertia) {
            $this->components->task('Publishing template panel (Vue pages)', fn () => $this->callSilently('vendor:publish', [
                '--tag' => 'whatsapp-cloud-inertia',
                '--force' => $force,
            ]) === self::SUCCESS);
        }

        $panelPrefix = ltrim((string) config('whatsapp-cloud.panel.prefix', 'whatsapp/cloud/templates'), '/');

        $this->newLine();
        $this->components->info('WhatsApp Cloud installed. Next steps:');
        $this->components->bulletList([
            'Run `php artisan migrate` (creates the whatsapp_numbers table).',
            'Set WHATSAPP_CLOUD_APP_SECRET, WHATSAPP_CLOUD_VERIFY_TOKEN and WHATSAPP_CLOUD_GRAPH_VERSION in .env.',
            'Register a number: fill whatsapp_numbers, or implement WhatsAppCredentials on your model and bind a resolver.',
            'Declare templates in config/whatsapp-cloud.php and create them on Meta with `php artisan whatsapp:template:create <name>`.',
            'Point Meta\'s webhook at /'.ltrim((string) config('whatsapp-cloud.webhook.prefix', 'webhooks/whatsapp/cloud'), '/').' and (optionally) listen to WhatsAppMessageReceived / WhatsAppStatusReceived.',
            'Send: WhatsApp::for($tenant)->sendTemplate(\'key\', [...]);',
            $hasInertia
                ? 'Template panel: run `npm run build`, then browse /'.$panelPrefix.' (guarded by the [web, auth] middleware; set WHATSAPP_CLOUD_PANEL_UI_TOKEN for extra defense).'
                : 'Template panel (optional): require inertiajs/inertia-laravel + @inertiajs/vue3, then re-run install and `npm run build` to manage templates at /'.$panelPrefix.'.',
        ]);

        return self::SUCCESS;
    }
}

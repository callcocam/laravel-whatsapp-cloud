<?php

namespace Callcocam\WhatsAppCloud\Console;

use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Console\Command;
use Throwable;

class GetTemplate extends Command
{
    protected $signature = 'whatsapp:template:get {name : The template name} {--tenant= : Tenant context to resolve credentials for}';

    protected $description = 'Show a WhatsApp template (approval status and components)';

    public function handle(WhatsAppManager $whatsapp): int
    {
        $name = (string) $this->argument('name');

        try {
            $template = $whatsapp->templateApi($this->option('tenant') ?: null)->getByName($name);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($template === null) {
            $this->warn("No template named '{$name}' on this WABA.");

            return self::SUCCESS;
        }

        $this->line((string) json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

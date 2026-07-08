<?php

namespace Callcocam\WhatsAppCloud\Console;

use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Console\Command;
use Throwable;

class CreateTemplate extends Command
{
    protected $signature = 'whatsapp:template:create
        {name : The definition file name (without .php)}
        {--tenant= : Tenant context to resolve credentials for}
        {--path= : Directory holding the <name>.php definition files}';

    protected $description = 'Create a WhatsApp template on Meta from a local definition file (submits for review)';

    public function handle(WhatsAppManager $whatsapp): int
    {
        $name = (string) $this->argument('name');

        $dir = (string) ($this->option('path') ?: config('whatsapp-cloud.definitions_path', ''));

        if ($dir === '') {
            $this->error('No definitions directory. Set `whatsapp-cloud.definitions_path` or pass --path.');

            return self::FAILURE;
        }

        $file = rtrim($dir, '/')."/{$name}.php";

        if (! is_file($file)) {
            $this->error("Definition not found: {$file}");

            return self::FAILURE;
        }

        $payload = require $file;

        if (! is_array($payload)) {
            $this->error("{$file} must return an array (use TemplateBuilder::...->toArray()).");

            return self::FAILURE;
        }

        try {
            $this->info("→ Creating template '{$payload['name']}' ({$payload['language']}, {$payload['category']})…");
            $result = $whatsapp->templateApi($this->option('tenant') ?: null)->create($payload);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ Submitted for review:');
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

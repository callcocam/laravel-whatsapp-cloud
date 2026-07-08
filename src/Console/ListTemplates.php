<?php

namespace Callcocam\WhatsAppCloud\Console;

use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Console\Command;
use Throwable;

class ListTemplates extends Command
{
    protected $signature = 'whatsapp:template:list {--tenant= : Tenant context to resolve credentials for}';

    protected $description = 'List the WhatsApp templates on the WABA and their approval status';

    public function handle(WhatsAppManager $whatsapp): int
    {
        try {
            $response = $whatsapp->templateApi($this->option('tenant') ?: null)->all();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = array_map(static fn (array $t): array => [
            $t['name'] ?? '?',
            $t['language'] ?? '?',
            $t['category'] ?? '?',
            $t['status'] ?? '?',
        ], $response['data'] ?? []);

        if ($rows === []) {
            $this->info('No templates on this WABA.');

            return self::SUCCESS;
        }

        $this->table(['Name', 'Language', 'Category', 'Status'], $rows);

        return self::SUCCESS;
    }
}

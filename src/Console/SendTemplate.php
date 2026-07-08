<?php

namespace Callcocam\WhatsAppCloud\Console;

use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Console\Command;
use Throwable;

class SendTemplate extends Command
{
    protected $signature = 'whatsapp:template:send
        {name : The approved template name}
        {to : Destination phone (e.g. 5548999999999)}
        {params?* : Positional body values, in order}
        {--tenant= : Tenant context to resolve credentials for}
        {--lang=pt_BR : Template language code}';

    protected $description = 'Send an approved WhatsApp template (positional body params)';

    public function handle(WhatsAppManager $whatsapp): int
    {
        $name = (string) $this->argument('name');
        $to = (string) $this->argument('to');
        /** @var list<string> $params */
        $params = (array) $this->argument('params');

        try {
            $this->info("→ Sending '{$name}' to {$to}".($params !== [] ? ' with ['.implode(', ', $params).']' : '').'…');
            $result = $whatsapp->templateApi($this->option('tenant') ?: null)
                ->send($name, $to, $params, (string) $this->option('lang'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ Sent:');
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

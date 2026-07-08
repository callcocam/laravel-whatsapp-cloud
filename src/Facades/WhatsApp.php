<?php

namespace Callcocam\WhatsAppCloud\Facades;

use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Callcocam\WhatsAppCloud\CloudApiClient for(mixed $context = null)
 * @method static \Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials credentials(mixed $context = null)
 * @method static \Callcocam\WhatsAppCloud\Templates\TemplateManager templateApi(mixed $context = null)
 * @method static \Callcocam\WhatsAppCloud\WhatsAppManager registerTemplate(string $key, \Callcocam\WhatsAppCloud\Templates\MetaTemplate $template)
 * @method static \Callcocam\WhatsAppCloud\Templates\TemplateRegistry templates()
 *
 * @see WhatsAppManager
 */
class WhatsApp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WhatsAppManager::class;
    }
}

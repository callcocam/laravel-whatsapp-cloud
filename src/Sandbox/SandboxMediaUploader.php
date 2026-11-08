<?php

namespace Callcocam\WhatsAppCloud\Sandbox;

use Callcocam\WhatsAppCloud\Contracts\MediaUploader;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Exceptions\SandboxException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

/**
 * The upload that goes nowhere: the file stays on the machine and the id only
 * says so. Same production guard as {@see SandboxTransport}.
 */
class SandboxMediaUploader implements MediaUploader
{
    public const PREFIX = 'sandbox-media-';

    public function __construct(Application $app)
    {
        if ($app->isProduction()) {
            throw new SandboxException(
                'The WhatsApp sandbox driver refuses to run in production. '
                .'Set WHATSAPP_CLOUD_DRIVER=cloud (and run `config:clear` and `queue:restart`).',
            );
        }
    }

    public function upload(WhatsAppCredentials $credentials, string $contents, string $mimeType, string $filename): string
    {
        return self::PREFIX.Str::lower((string) Str::ulid());
    }
}

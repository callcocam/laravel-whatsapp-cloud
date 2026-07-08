<?php

namespace Callcocam\WhatsAppCloud\Support;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentialsResolver;
use Callcocam\WhatsAppCloud\Models\WhatsAppNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * An optional resolver that looks up a credentials model by a scalar key (e.g.
 * the tenant slug on the `key` column of the {@see WhatsAppNumber}
 * table). Bind it in a provider when a new project wants the model default
 * without writing a resolver:
 *
 *   $this->app->bind(WhatsAppCredentialsResolver::class, fn () =>
 *       new ModelCredentialsResolver(WhatsAppNumber::class, 'key'));
 */
final class ModelCredentialsResolver implements WhatsAppCredentialsResolver
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private readonly string $model,
        private readonly string $keyColumn = 'key',
    ) {}

    public function resolve(mixed $context): ?WhatsAppCredentials
    {
        if ($context instanceof WhatsAppCredentials) {
            return $context;
        }

        if (! is_scalar($context)) {
            return null;
        }

        $record = $this->model::query()->where($this->keyColumn, $context)->first();

        return $record instanceof WhatsAppCredentials ? $record : null;
    }
}

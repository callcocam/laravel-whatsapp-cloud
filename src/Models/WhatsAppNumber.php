<?php

namespace Callcocam\WhatsAppCloud\Models;

use Callcocam\WhatsAppCloud\Contracts\WhatsAppCredentials;
use Callcocam\WhatsAppCloud\Support\HasWhatsAppCredentials;
use Callcocam\WhatsAppCloud\Support\ModelCredentialsResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * The package's default, opt-in credentials model (`whatsapp_numbers` table).
 * A new project can store its Meta numbers here and resolve them by `key` with
 * {@see ModelCredentialsResolver}, instead of
 * implementing {@see WhatsAppCredentials} on its own model.
 *
 * @property string|null $key
 * @property string|null $waba_id
 * @property string|null $phone_number_id
 * @property string|null $cloud_access_token
 * @property string|null $app_id
 * @property string|null $verified_name
 * @property string|null $quality_rating
 * @property string|null $messaging_limit
 * @property string|null $graph_version
 */
class WhatsAppNumber extends Model implements WhatsAppCredentials
{
    use HasWhatsAppCredentials;

    protected $table = 'whatsapp_numbers';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['cloud_access_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cloud_access_token' => 'encrypted',
        ];
    }
}

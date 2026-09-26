<?php

namespace Callcocam\WhatsAppCloud\Models;

use Callcocam\WhatsAppCloud\Settings\SettingsStore;
use Illuminate\Database\Eloquent\Model;

/**
 * One configuration value saved from the setup panel (`whatsapp_settings`).
 * Read through {@see SettingsStore}, never directly.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
class WhatsAppSetting extends Model
{
    protected $table = 'whatsapp_settings';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}

<?php

namespace Callcocam\WhatsAppCloud\Settings;

use Callcocam\WhatsAppCloud\Models\WhatsAppSetting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Configuration saved from the setup panel, layered over `config/whatsapp-cloud`.
 *
 * Values live encrypted in `whatsapp_settings`. On boot the provider calls
 * {@see apply()}, so every part of the package keeps reading plain config and
 * never knows where a value came from. A value saved here WINS over the .env;
 * an empty value means "not set here" and leaves the .env in charge.
 *
 * Long-running processes (queue workers, Octane) read the table once at boot —
 * after saving, restart them, exactly as after editing .env.
 */
class SettingsStore
{
    /**
     * Setting key => config path it overrides.
     */
    public const KEYS = [
        'app_id' => 'whatsapp-cloud.app_id',
        'app_secret' => 'whatsapp-cloud.app_secret',
        'verify_token' => 'whatsapp-cloud.verify_token',
        'graph_version' => 'whatsapp-cloud.graph_version',
        'embedded_signup_config_id' => 'whatsapp-cloud.embedded_signup.config_id',
        'register_pin' => 'whatsapp-cloud.embedded_signup.register_pin',
        'default_phone_number_id' => 'whatsapp-cloud.default.phone_number_id',
        'default_waba_id' => 'whatsapp-cloud.default.waba_id',
        'default_access_token' => 'whatsapp-cloud.default.access_token',
        'webhook_subscribed_at' => 'whatsapp-cloud.setup.webhook_subscribed_at',
    ];

    /**
     * Keys that are secrets: masked in the panel.
     */
    public const SECRETS = ['app_secret', 'verify_token', 'register_pin', 'default_access_token'];

    /**
     * Stored values, loaded once per process.
     *
     * @var array<string, string>|null
     */
    protected ?array $stored = null;

    /**
     * The config values each stored key covered up — i.e. what the .env says —
     * so they can be restored and the panel can tell the two sources apart.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    public function __construct(protected readonly Repository $config) {}

    /**
     * Overlay the stored values on the config. Safe to call before the table
     * exists (fresh install, `migrate` itself): it then does nothing.
     */
    public function apply(): void
    {
        $stored = $this->all();

        foreach (self::KEYS as $key => $path) {
            if (filled($stored[$key] ?? null)) {
                // Remember the .env value the first time we cover it.
                if (! array_key_exists($key, $this->original)) {
                    $this->original[$key] = $this->config->get($path);
                }

                $this->config->set($path, $stored[$key]);
            } elseif (array_key_exists($key, $this->original)) {
                // Removed from the panel: hand the key back to the .env.
                $this->config->set($path, $this->original[$key]);
                unset($this->original[$key]);
            }
        }
    }

    /**
     * Every non-empty stored value.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        try {
            $this->stored = WhatsAppSetting::query()
                ->whereIn('key', array_keys(self::KEYS))
                ->get()
                ->filter(fn (WhatsAppSetting $s) => filled($s->value))
                ->mapWithKeys(fn (WhatsAppSetting $s) => [$s->key => (string) $s->value])
                ->all();
        } catch (Throwable) {
            // No table yet, no database, or a key rotated since the value was
            // written: fall back to plain config rather than breaking boot.
            $this->stored = [];
        }

        return $this->stored;
    }

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Save values (null or '' deletes the key) and re-apply them to this process.
     *
     * @param  array<string, string|null>  $values
     */
    public function put(array $values): void
    {
        foreach (array_keys($values) as $key) {
            if (! array_key_exists($key, self::KEYS)) {
                throw new InvalidArgumentException("Unknown WhatsApp setting [{$key}].");
            }
        }

        // Related keys (app id + secret, the default number's three) change
        // together or not at all.
        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                if (blank($value)) {
                    WhatsAppSetting::query()->where('key', $key)->delete();

                    continue;
                }

                WhatsAppSetting::query()->updateOrCreate(['key' => $key], ['value' => trim((string) $value)]);
            }
        });

        $this->stored = null;
        $this->apply();
    }

    /**
     * Where each setting currently comes from: 'panel', 'env' or null (unset).
     *
     * @return array<string, 'panel'|'env'|null>
     */
    public function sources(): array
    {
        $sources = [];

        foreach (self::KEYS as $key => $path) {
            $env = array_key_exists($key, $this->original) ? $this->original[$key] : $this->config->get($path);

            $sources[$key] = match (true) {
                filled($this->get($key)) => 'panel',
                filled($env) => 'env',
                default => null,
            };
        }

        return $sources;
    }

    /**
     * The effective value of every key (panel or .env), for export.
     *
     * @return array<string, string>
     */
    public function effective(): array
    {
        $values = [];

        foreach (self::KEYS as $key => $path) {
            $value = $this->config->get($path);

            if (filled($value) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }
}

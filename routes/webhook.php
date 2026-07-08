<?php

use Callcocam\WhatsAppCloud\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud webhook
|--------------------------------------------------------------------------
|
| Registered by the service provider under the configured prefix and
| middleware (default: `webhooks/whatsapp/cloud`, the `api` group so there is
| no CSRF). Set `whatsapp-cloud.webhook.enabled = false` to register your own.
|
*/

Route::get('/', [WebhookController::class, 'verify'])->name('verify');
Route::post('/', [WebhookController::class, 'store'])->name('store');

<?php

use Callcocam\WhatsAppCloud\Http\Controllers\TemplatePanelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud template panel (Inertia + Vue)
|--------------------------------------------------------------------------
|
| Registered by the service provider under the configured prefix and
| middleware (default: `whatsapp/cloud/templates`, the `['web', 'auth']`
| group). Only loads when Inertia is installed. Set
| `whatsapp-cloud.panel.enabled = false` to disable it entirely.
|
| Mutations use Inertia visits (POST/DELETE) and redirect back — CSRF is
| handled automatically by Inertia's axios (XSRF-TOKEN cookie).
|
*/

Route::get('/', [TemplatePanelController::class, 'index'])->name('index');
Route::post('/', [TemplatePanelController::class, 'store'])->name('store');
Route::post('/send', [TemplatePanelController::class, 'send'])->name('send');
Route::post('/{id}/edit', [TemplatePanelController::class, 'update'])
    ->where('id', '[0-9]+')
    ->name('update');
Route::delete('/{name}', [TemplatePanelController::class, 'destroy'])
    ->where('name', '[a-z0-9_]+')
    ->name('destroy');

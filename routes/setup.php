<?php

use Callcocam\WhatsAppCloud\Http\Controllers\SetupPanelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud setup wizard
|--------------------------------------------------------------------------
|
| Registered by the service provider under `whatsapp-cloud.setup` (default:
| `whatsapp/cloud/setup`, the `['web', 'auth']` group + gate). It reads and
| writes every Meta credential — keep it behind an admin gate.
|
*/

Route::get('/', [SetupPanelController::class, 'index'])->name('index');
Route::post('/app', [SetupPanelController::class, 'saveApp'])->name('app');
Route::post('/webhook', [SetupPanelController::class, 'subscribeWebhook'])->name('webhook');
Route::post('/signup', [SetupPanelController::class, 'saveSignup'])->name('signup');
Route::post('/number', [SetupPanelController::class, 'saveNumber'])->name('number');
Route::post('/default/{number}', [SetupPanelController::class, 'setDefault'])
    ->where('number', '[0-9]+')
    ->name('default');
Route::post('/test', [SetupPanelController::class, 'sendTest'])->name('test');
Route::post('/diagnose', [SetupPanelController::class, 'diagnose'])->name('diagnose');
Route::get('/export', [SetupPanelController::class, 'export'])->name('export');
Route::post('/import', [SetupPanelController::class, 'import'])->name('import');

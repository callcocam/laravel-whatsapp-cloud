<?php

use Callcocam\WhatsAppCloud\Http\Controllers\NumbersPanelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud connected numbers (Embedded Signup)
|--------------------------------------------------------------------------
|
| Registered by the service provider under `whatsapp-cloud.embedded_signup`
| (default: `whatsapp/cloud/numbers`, the `['web', 'auth']` group). Only loads
| when Inertia is installed.
|
*/

Route::get('/', [NumbersPanelController::class, 'index'])->name('index');
Route::post('/', [NumbersPanelController::class, 'store'])->name('store');
Route::post('/{number}/refresh', [NumbersPanelController::class, 'refresh'])
    ->where('number', '[0-9]+')
    ->name('refresh');
Route::delete('/{number}', [NumbersPanelController::class, 'destroy'])
    ->where('number', '[0-9]+')
    ->name('destroy');

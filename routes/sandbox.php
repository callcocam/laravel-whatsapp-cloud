<?php

use Callcocam\WhatsAppCloud\Http\Controllers\SandboxController;
use Illuminate\Support\Facades\Route;

/*
 * The sandbox screen. Registered ONLY when the driver is `sandbox` and the app is
 * not in production — see WhatsAppCloudServiceProvider::registerSandboxRoutes().
 *
 * The two halves of a conversation are asymmetric on purpose. Outbound messages
 * have no route here: the app sends them, through its own code, and the transport
 * catches them. Only the OTHER side of the conversation — the person — needs a
 * button to press.
 */

Route::get('/', [SandboxController::class, 'index'])->name('index');

// Polled ~1.5s. Deliberately payload-free: raw envelopes would be hundreds of KB
// a second. The inspector fetches one message's detail on demand.
Route::get('/state', [SandboxController::class, 'state'])->name('state');
Route::get('/messages/{message}', [SandboxController::class, 'message'])->name('message');

Route::post('/participants', [SandboxController::class, 'storeParticipant'])->name('participants.store');

// The person answers.
Route::post('/reply', [SandboxController::class, 'reply'])->name('reply');
Route::post('/tap', [SandboxController::class, 'tap'])->name('tap');

// The system speaks first — for rehearsing a round the app itself would normally
// start (and for the operator-initiated flow).
Route::post('/send-template', [SandboxController::class, 'sendTemplate'])->name('send-template');
Route::post('/send-text', [SandboxController::class, 'sendText'])->name('send-text');

// Rehearsing the things that go wrong.
Route::post('/status', [SandboxController::class, 'status'])->name('status');
Route::post('/faults', [SandboxController::class, 'arm'])->name('faults');
Route::post('/close-window', [SandboxController::class, 'closeWindow'])->name('close-window');

Route::post('/reset', [SandboxController::class, 'reset'])->name('reset');

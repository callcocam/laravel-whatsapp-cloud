<?php

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxConversation;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxMessage;
use Callcocam\WhatsAppCloud\Sandbox\Sandbox;
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Nothing in this suite may touch Meta. If a stray request escapes, the
    // sandbox is not a sandbox.
    Http::preventStrayRequests();
});

it('stores an outbound template instead of sending it', function () {
    WhatsApp::registerTemplate('assignment', new MetaTemplate(
        'coordena_assignment', 'pt_BR', 'utility', ['name', 'event'],
    ));

    $result = WhatsApp::for()->sendTemplate('5548999999999', TemplateMessage::make('assignment', [
        'name' => 'Maria', 'event' => 'Congresso',
    ]));

    expect($result->messageId)->toStartWith('wamid.SANDBOX.');

    $message = SandboxMessage::sole();

    expect($message->direction)->toBe('outbound')
        ->and($message->type)->toBe('template')
        ->and($message->template_name)->toBe('coordena_assignment')
        // The envelope is kept verbatim — this is what the inspector shows.
        ->and($message->envelope['template']['components'][0]['parameters'][0]['text'])->toBe('Maria')
        ->and($message->conversation->wa_id)->toBe('5548999999999');
});

it('refuses free text before the person has ever replied', function () {
    // Meta's 24h rule, enforced for real. This is error 131047 — the single most
    // common production surprise, and one nobody can otherwise provoke.
    try {
        WhatsApp::for()->sendSessionText('5548999999999', 'Olá!');
        expect(false)->toBeTrue('expected the closed window to reject this');
    } catch (CloudApiException $e) {
        expect($e->errorCode)->toBe(131047)
            // Terminal: the app must log and stop, not let the queue retry forever.
            ->and($e->isTerminal())->toBeTrue();
    }

    expect(SandboxMessage::count())->toBe(0);
});

it('opens the 24h window when the person replies, and free text then works', function () {
    $sandbox = app(Sandbox::class);
    $maria = $sandbox->participant('5548999999999', 'Maria');

    expect($maria->windowIsOpen())->toBeFalse();

    $sandbox->reply($maria, 'Oi, pode falar');

    expect($maria->refresh()->windowIsOpen())->toBeTrue();

    WhatsApp::for()->sendSessionText('5548999999999', 'Ótimo!');

    expect(SandboxMessage::where('direction', 'outbound')->sole()->rendered_text)->toBe('Ótimo!');
});

it('closes the window on demand so 131047 can be rehearsed', function () {
    $sandbox = app(Sandbox::class);
    $maria = $sandbox->participant('5548999999999', 'Maria');
    $sandbox->reply($maria, 'oi');

    // Jump to the moment the window has just lapsed, rather than waiting a day.
    $maria->closeWindow();

    expect(fn () => WhatsApp::for()->sendSessionText('5548999999999', 'ainda aí?'))
        ->toThrow(CloudApiException::class);

    // A template still gets through — that is the whole point of templates.
    WhatsApp::registerTemplate('ping', new MetaTemplate('coordena_ping', 'pt_BR', 'utility', []));
    WhatsApp::for()->sendTemplate('5548999999999', TemplateMessage::make('ping'));

    expect(SandboxMessage::where('type', 'template')->count())->toBe(1);
});

it('fires an armed fault once, then disarms it', function () {
    $sandbox = app(Sandbox::class);
    $maria = $sandbox->participant('5548999999999', 'Maria');
    $sandbox->reply($maria, 'oi');

    $maria->arm('rate_limited');

    try {
        WhatsApp::for()->sendSessionText('5548999999999', 'primeira');
        expect(false)->toBeTrue('expected the armed fault to fire');
    } catch (CloudApiException $e) {
        expect($e->errorCode)->toBe(80007)
            // Retryable on purpose: this is the branch that exercises the queue's
            // backoff, which the happy path never touches.
            ->and($e->isTerminal())->toBeFalse();
    }

    // Armed faults fire once. A sticky one would turn a deliberate rehearsal into
    // a sandbox that just seems broken.
    WhatsApp::for()->sendSessionText('5548999999999', 'segunda');

    expect(SandboxMessage::where('direction', 'outbound')->count())->toBe(1);
});

it('runs the app listeners on a reply, exactly as production would', function () {
    $heard = null;
    Event::listen(WhatsAppMessageReceived::class, function ($event) use (&$heard) {
        $heard = $event->message['button']['text'] ?? $event->message['text']['body'] ?? null;
    });

    $sandbox = app(Sandbox::class);
    $maria = $sandbox->participant('5548999999999', 'Maria');

    $result = $sandbox->tapTemplateButton($maria, 'Aceitar', 'wamid.SANDBOX.ORIGINAL');

    expect($heard)->toBe('Aceitar')
        ->and($result->succeeded())->toBeTrue();
});

it('carries a listener exception onto the message row for the inspector', function () {
    Event::listen(WhatsAppMessageReceived::class, function () {
        throw new RuntimeException('o listener do app quebrou');
    });

    $sandbox = app(Sandbox::class);
    $maria = $sandbox->participant('5548999999999', 'Maria');
    $sandbox->reply($maria, 'oi');

    $inbound = SandboxMessage::where('direction', 'inbound')->sole();

    expect($inbound->meta['failure']['class'])->toBe(RuntimeException::class)
        ->and($inbound->meta['failure']['message'])->toBe('o listener do app quebrou');
});

it('lets the operator be just another participant', function () {
    // The whole handoff story: the system reaches the customer, the customer
    // answers, a listener asks the operator, the operator decides. Two threads,
    // one engine — no special "operator" machinery anywhere.
    $sandbox = app(Sandbox::class);

    $maria = $sandbox->participant('5548999999999', 'Maria', 'customer');
    $suporte = $sandbox->participant('5548911111111', 'Suporte', 'operator');

    Event::listen(WhatsAppMessageReceived::class, function ($event) {
        if ($event->message['from'] === '5548999999999') {
            WhatsApp::for()->sendSessionText('5548911111111', 'Maria respondeu. Aprova?');
        }
    });

    $sandbox->reply($suporte, 'pronto');   // opens the operator's window
    $sandbox->reply($maria, 'Confirmo!');  // triggers the handoff

    expect(SandboxMessage::where('conversation_id', $suporte->id)
        ->where('direction', 'outbound')
        ->sole()
        ->rendered_text
    )->toBe('Maria respondeu. Aprova?');
});

it('renders the template body from the local definition, with no Meta round-trip', function () {
    // The point of the whole thing: rehearse a template that has NEVER been
    // submitted. Http::preventStrayRequests() is the proof — one call to Meta and
    // this test dies.
    $dir = sys_get_temp_dir().'/wa-defs-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/coordena_assignment.php', <<<'PHP'
        <?php
        return [
            'name' => 'coordena_assignment',
            'language' => 'pt_BR',
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => 'Olá, {{1}}! Você foi escalado para {{2}}. Confirma?'],
                ['type' => 'FOOTER', 'text' => 'Coordena'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Aceitar'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Recusar'],
                ]],
            ],
        ];
        PHP);

    config()->set('whatsapp-cloud.definitions_path', $dir);

    WhatsApp::registerTemplate('assignment', new MetaTemplate(
        'coordena_assignment', 'pt_BR', 'utility', ['name', 'event'],
    ));

    WhatsApp::for()->sendTemplate('5548999999999', TemplateMessage::make('assignment', [
        'name' => 'Maria', 'event' => 'Congresso',
    ]));

    $message = SandboxMessage::sole();

    expect($message->rendered_text)->toBe('Olá, Maria! Você foi escalado para Congresso. Confirma?')
        ->and($message->meta['template_source'])->toBe('definition')
        // The buttons are known, so the screen can offer them to be tapped.
        ->and($message->template_components[2]['buttons'][0]['text'])->toBe('Aceitar');

    unlink($dir.'/coordena_assignment.php');
    rmdir($dir);
});

it('creates the conversation from the first outbound message', function () {
    WhatsApp::registerTemplate('ping', new MetaTemplate('coordena_ping', 'pt_BR', 'utility', []));

    WhatsApp::for()->sendTemplate('5548777777777', TemplateMessage::make('ping'));

    expect(SandboxConversation::sole()->wa_id)->toBe('5548777777777');
});

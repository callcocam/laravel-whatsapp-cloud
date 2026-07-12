<?php

use Callcocam\WhatsAppCloud\Events\WhatsAppMessageReceived;
use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxConversation;
use Callcocam\WhatsAppCloud\Sandbox\Models\SandboxMessage;
use Callcocam\WhatsAppCloud\Sandbox\Sandbox;
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    // Nothing in this suite may touch Meta. If a stray request escapes, the
    // sandbox is not a sandbox.
    Http::preventStrayRequests();
    $this->actingAs(new GenericUser(['id' => 1]));
});

it('renders the sandbox page', function () {
    $this->get('whatsapp/cloud/sandbox')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('WhatsAppCloud/Sandbox/Index')
            ->has('conversations')
            ->has('faults')
            ->where('business.phone_number_id', '111222333'),
        );
});

it('polls state as plain JSON, without the raw payloads', function () {
    $maria = app(Sandbox::class)->participant('5548999999999', 'Maria');
    app(Sandbox::class)->reply($maria, 'oi');

    $response = $this->getJson('whatsapp/cloud/sandbox/state?conversation='.$maria->id)->assertOk();

    expect($response->json('messages.0.text'))->toBe('oi')
        // Raw payloads would be hundreds of KB every 1.5s. They are fetched per
        // message, on click.
        ->and($response->json('messages.0'))->not->toHaveKey('inbound_payload');
});

it('serves one message in full for the inspector', function () {
    $maria = app(Sandbox::class)->participant('5548999999999', 'Maria');
    app(Sandbox::class)->reply($maria, 'oi');

    $message = SandboxMessage::sole();

    $this->getJson("whatsapp/cloud/sandbox/messages/{$message->id}")
        ->assertOk()
        ->assertJsonPath('inbound_payload.entry.0.changes.0.value.messages.0.text.body', 'oi')
        ->assertJsonPath('meta.status', 200);
});

it('lets you answer as the contact, and runs the app listeners', function () {
    $heard = null;
    Event::listen(WhatsAppMessageReceived::class, function ($event) use (&$heard) {
        $heard = $event->message['text']['body'];
    });

    $maria = app(Sandbox::class)->participant('5548999999999', 'Maria');

    $this->post('whatsapp/cloud/sandbox/reply', [
        'conversation' => $maria->id,
        'text' => 'Confirmo!',
    ])->assertRedirect();

    expect($heard)->toBe('Confirmo!');
});

it('turns a tapped template button into the type:button webhook', function () {
    $seen = null;
    Event::listen(WhatsAppMessageReceived::class, function ($event) use (&$seen) {
        $seen = $event->message;
    });

    $maria = app(Sandbox::class)->participant('5548999999999', 'Maria');

    $this->post('whatsapp/cloud/sandbox/tap', [
        'conversation' => $maria->id,
        'kind' => 'template',
        'text' => 'Aceitar',
        'reply_to' => 'wamid.SANDBOX.ABC',
    ])->assertRedirect();

    expect($seen['type'])->toBe('button')
        ->and($seen['button']['text'])->toBe('Aceitar')
        // The correlation back to the message we sent.
        ->and($seen['context']['id'])->toBe('wamid.SANDBOX.ABC');
});

it('shows a closed window as a flash instead of a 500', function () {
    // A CloudApiException here is the sandbox WORKING. It belongs on the screen,
    // next to the message — not in a log.
    $maria = app(Sandbox::class)->participant('5548999999999', 'Maria');

    $this->post('whatsapp/cloud/sandbox/send-text', [
        'conversation' => $maria->id,
        'text' => 'oi',
    ])
        ->assertRedirect()
        ->assertSessionHas('flash', fn (array $flash) => $flash['terminal'] === true
            && str_contains($flash['error'], '24 hours'));
});

it('arms a fault and closes the window from the screen', function () {
    $maria = app(Sandbox::class)->participant('5548999999999', 'Maria');
    app(Sandbox::class)->reply($maria, 'oi');

    $this->post('whatsapp/cloud/sandbox/faults', ['conversation' => $maria->id, 'fault' => 'template_paused']);
    expect($maria->refresh()->armedFault()->code)->toBe(132015);

    $this->post('whatsapp/cloud/sandbox/close-window', ['conversation' => $maria->id]);
    expect($maria->refresh()->windowIsOpen())->toBeFalse();
});

it('rejects a participant with a nonsense number', function () {
    $this->post('whatsapp/cloud/sandbox/participants', ['wa_id' => '123', 'name' => 'X'])
        ->assertSessionHasErrors('wa_id');

    expect(SandboxConversation::count())->toBe(0);
});

it('wipes the sandbox on reset', function () {
    $maria = app(Sandbox::class)->participant('5548999999999', 'Maria');
    app(Sandbox::class)->reply($maria, 'oi');

    $this->post('whatsapp/cloud/sandbox/reset')->assertRedirect();

    expect(SandboxConversation::count())->toBe(0)
        ->and(SandboxMessage::count())->toBe(0);
});

it('offers templates that exist only as local definition files', function () {
    // The whole point: a template shows up here BEFORE it is submitted to Meta.
    $dir = sys_get_temp_dir().'/wa-defs-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/x.php', '<?php return ['
        ."'name' => 'coordena_x', 'language' => 'pt_BR', 'category' => 'UTILITY',"
        ."'components' => [['type' => 'BODY', 'text' => 'Olá {{1}}, tudo bem?']]];");

    config()->set('whatsapp-cloud.definitions_path', $dir);

    $this->getJson('whatsapp/cloud/sandbox/state')
        ->assertOk()
        ->assertJsonPath('templates.0.name', 'coordena_x')
        ->assertJsonPath('templates.0.variables', 1);

    unlink($dir.'/x.php');
    rmdir($dir);
});

it('advances the delivery status, firing the real status webhook', function () {
    WhatsApp::registerTemplate('ping', new MetaTemplate('coordena_ping', 'pt_BR', 'utility', []));
    WhatsApp::for()->sendTemplate('5548999999999', TemplateMessage::make('ping'));

    $message = SandboxMessage::sole();

    $this->post('whatsapp/cloud/sandbox/status', [
        'conversation' => $message->conversation_id,
        'message' => $message->id,
        'status' => 'read',
    ])->assertRedirect();

    expect($message->refresh()->delivery_status)->toBe('read');
});

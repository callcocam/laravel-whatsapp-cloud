<?php

use Callcocam\WhatsAppCloud\CloudApiClient;
use Callcocam\WhatsAppCloud\Facades\WhatsApp;
use Callcocam\WhatsAppCloud\Messages\TemplateMessage;
use Callcocam\WhatsAppCloud\Templates\MetaTemplate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('resolves a sender through the facade', function () {
    expect(WhatsApp::for())->toBeInstanceOf(CloudApiClient::class);
});

it('registers a template and sends it through the facade', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w.F']]])]);

    WhatsApp::registerTemplate('welcome', new MetaTemplate('coordena_welcome', 'pt_BR', 'utility', ['name']));

    WhatsApp::for()->sendTemplate('5548999999999', TemplateMessage::make('welcome', ['name' => 'Ana']));

    Http::assertSent(fn (Request $r) => $r->data()['template']['name'] === 'coordena_welcome');
});

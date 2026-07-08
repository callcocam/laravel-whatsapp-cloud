<?php

use Callcocam\WhatsAppCloud\Exceptions\CloudApiException;
use Callcocam\WhatsAppCloud\WhatsAppManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates a template on the WABA endpoint', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'tpl_1', 'status' => 'PENDING'])]);

    $result = app(WhatsAppManager::class)->templateApi()->create([
        'name' => 'coordena_x', 'language' => 'pt_BR', 'category' => 'UTILITY',
        'components' => [['type' => 'BODY', 'text' => 'Oi {{1}}!', 'example' => ['body_text' => [['Ana']]]]],
    ]);

    expect($result['status'])->toBe('PENDING');

    Http::assertSent(function (Request $r) {
        // config default waba_id is 999888777 (see TestCase).
        return $r->method() === 'POST'
            && str_contains($r->url(), '/v21.0/999888777/message_templates')
            && $r->data()['name'] === 'coordena_x';
    });
});

it('lists templates on the WABA', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['name' => 'coordena_x', 'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED'],
    ]])]);

    $response = app(WhatsAppManager::class)->templateApi()->all();

    expect($response['data'][0]['status'])->toBe('APPROVED');
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/message_templates'));
});

it('sends an approved template through the phone number endpoint', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.S']]])]);

    app(WhatsAppManager::class)->templateApi()->send('coordena_x', '5548999999999', ['Ana']);

    Http::assertSent(function (Request $r) {
        // config default phone_number_id is 111222333 (see TestCase).
        return str_contains($r->url(), '/v21.0/111222333/messages')
            && $r->data()['template']['name'] === 'coordena_x'
            && $r->data()['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'Ana'];
    });
});

it('throws a CloudApiException on a failed management call', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad', 'code' => 100]], 400)]);

    expect(fn () => app(WhatsAppManager::class)->templateApi()->all())
        ->toThrow(CloudApiException::class);
});

it('runs the whatsapp:template:list command', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['name' => 'coordena_x', 'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED'],
    ]])]);

    $this->artisan('whatsapp:template:list')
        ->assertExitCode(0)
        ->expectsOutputToContain('coordena_x');
});

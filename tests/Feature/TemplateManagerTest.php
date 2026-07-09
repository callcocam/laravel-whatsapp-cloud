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

it('edits an existing template by id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    app(WhatsAppManager::class)->templateApi()->edit('123', [
        ['type' => 'BODY', 'text' => 'Oi {{1}}, tudo certo!', 'example' => ['body_text' => [['Ana']]]],
    ], 'utility');

    Http::assertSent(function (Request $r) {
        // Edit hits the template id directly on the graph base, not the WABA node.
        return $r->method() === 'POST'
            && str_contains($r->url(), '/v21.0/123')
            && ! str_contains($r->url(), 'message_templates')
            && $r->data()['category'] === 'UTILITY'
            && $r->data()['components'][0]['type'] === 'BODY';
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

it('reads estimated costs from the WABA conversation_analytics edge', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['conversation_analytics' => ['data' => [[
        'data_points' => [['conversation' => 10, 'cost' => 1.5, 'conversation_category' => 'MARKETING']],
    ]]]])]);

    app(WhatsAppManager::class)->templateApi()->costs(1_700_000_000, 1_700_500_000);

    Http::assertSent(function (Request $r) {
        $url = urldecode($r->url());

        return $r->method() === 'GET'
            && str_contains($url, '/v21.0/999888777?')
            && str_contains($url, 'conversation_analytics.start(1700000000).end(1700500000)')
            && str_contains($url, "metric_types(['COST','CONVERSATION'])")
            && str_contains($url, "dimensions(['CONVERSATION_CATEGORY'])");
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
